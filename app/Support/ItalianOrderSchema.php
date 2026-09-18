<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Resume MySQL DDL interrupted before Laravel recorded the migration. */
class ItalianOrderSchema
{
    public static function create(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) use ($definition) {
                $definition($table);
                self::matchReferenceTypes($table);
            });
        }

        $expected = new Blueprint(Schema::getConnection(), $name);
        $definition($expected);
        foreach ($expected->getColumns() as $column) {
            if (! Schema::hasColumn($name, $column->name)) {
                throw new RuntimeException("Tabella {$name} incompleta: manca la colonna {$column->name}. Nessun dato eliminato.");
            }
            foreach (['unique', 'index'] as $type) {
                if ($column->$type) {
                    self::index($name, [$column->name], $type);
                }
            }
        }
        foreach ($expected->getCommands() as $command) {
            if (in_array($command->name, ['unique', 'index'], true)) {
                self::index($name, $command->columns, $command->name);
            }
            if ($command->name !== 'foreign') {
                continue;
            }
            $existing = collect(Schema::getForeignKeys($name))->first(fn ($key) => $key['columns'] === $command->columns);
            if ($existing) {
                if ($existing['foreign_table'] !== (string) $command->on || $existing['foreign_columns'] !== (array) $command->references
                    || ($command->onDelete && strtolower($existing['on_delete'] ?? '') !== strtolower($command->onDelete))) {
                    throw new RuntimeException("Relazione non compatibile nella tabella {$name}.");
                }

                continue;
            }
            foreach ($command->columns as $index => $columnName) {
                if (DB::table($name)->whereNotNull($columnName)
                    ->whereNotIn($columnName, DB::table((string) $command->on)->select(((array) $command->references)[$index]))->exists()) {
                    throw new RuntimeException("Relazione {$name}.{$columnName} incompleta: riferimenti non presenti nella tabella collegata. Nessun dato eliminato.");
                }
            }
            // SQLite exports can use signed IDs. Align only missing FK columns
            // with their actual parent, rather than altering users or order IDs.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                foreach ($command->columns as $index => $columnName) {
                    $parentType = Schema::getColumnType((string) $command->on, ((array) $command->references)[$index], true);
                    $currentType = Schema::getColumnType($name, $columnName, true);
                    if ($currentType !== $parentType) {
                        $column = collect($expected->getColumns())->first(fn ($column) => $column->name === $columnName);
                        [$type, $unsigned] = self::integerType($parentType);
                        Schema::table($name, fn (Blueprint $table) => $table->addColumn($type, $columnName)
                            ->unsigned($unsigned)->nullable((bool) $column->nullable)->change());
                    }
                }
            }
            Schema::table($name, function (Blueprint $table) use ($command) {
                $foreign = $table->foreign($command->columns, $command->index)
                    ->references($command->references)->on($command->on);
                if ($command->onDelete) {
                    $foreign->onDelete($command->onDelete);
                }
                if ($command->onUpdate) {
                    $foreign->onUpdate($command->onUpdate);
                }
            });
        }
    }

    public static function index(string $table, array $columns, string $type = 'unique'): void
    {
        if (! Schema::hasIndex($table, $columns, $type === 'unique' ? 'unique' : null)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->$type($columns));
        }
    }

    private static function matchReferenceTypes(Blueprint $table): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($table->getCommands() as $command) {
            if ($command->name !== 'foreign') {
                continue;
            }
            foreach ($command->columns as $index => $columnName) {
                [$type, $unsigned] = self::integerType(Schema::getColumnType((string) $command->on, ((array) $command->references)[$index], true));
                foreach ($table->getColumns() as $column) {
                    if ($column->name === $columnName) {
                        $column->type = $type;
                        $column->unsigned = $unsigned;
                    }
                }
            }
        }
    }

    private static function integerType(string $definition): array
    {
        $type = match (true) {
            str_starts_with($definition, 'bigint') => 'bigInteger',
            str_starts_with($definition, 'int') => 'integer',
            str_starts_with($definition, 'mediumint') => 'mediumInteger',
            str_starts_with($definition, 'smallint') => 'smallInteger',
            str_starts_with($definition, 'tinyint') => 'tinyInteger',
            default => throw new RuntimeException('Tipo identificativo non compatibile: '.$definition),
        };

        return [$type, str_contains($definition, 'unsigned')];
    }
}
