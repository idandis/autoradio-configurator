<?php

declare(strict_types=1);

$source = $argv[1] ?? __DIR__.'/../database/database.sqlite';
$target = $argv[2] ?? __DIR__.'/../exports/database_mysql.sql';

if (! is_file($source)) {
    fwrite(STDERR, "Database SQLite non trovato: {$source}\n");
    exit(1);
}

if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
    fwrite(STDERR, "Impossibile creare la cartella: ".dirname($target)."\n");
    exit(1);
}

$db = new PDO('sqlite:'.$source, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$out = fopen($target, 'wb');

if ($out === false) {
    fwrite(STDERR, "Impossibile creare il file: {$target}\n");
    exit(1);
}

function ident(string $value): string
{
    return '`'.str_replace('`', '``', $value).'`';
}

function mysqlName(string $value): string
{
    if (strlen($value) <= 64) {
        return $value;
    }

    return substr($value, 0, 51).'_'.substr(sha1($value), 0, 12);
}

function mysqlType(string $sqliteType): string
{
    $type = strtoupper(trim($sqliteType));

    return match (true) {
        str_contains($type, 'TINYINT') => 'TINYINT(1)',
        str_contains($type, 'INT') => 'BIGINT',
        str_contains($type, 'CHAR'), str_contains($type, 'CLOB') => 'VARCHAR(255)',
        str_contains($type, 'TEXT') => 'LONGTEXT',
        str_contains($type, 'BLOB'), $type === '' => 'LONGBLOB',
        str_contains($type, 'REAL'), str_contains($type, 'FLOA'), str_contains($type, 'DOUB') => 'DOUBLE',
        str_contains($type, 'NUMERIC'), str_contains($type, 'DECIMAL') => 'DECIMAL(15,4)',
        str_contains($type, 'DATE'), str_contains($type, 'TIME') => 'DATETIME',
        default => 'LONGTEXT',
    };
}

function mysqlValue(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'".str_replace(
        ["\\", "\0", "\n", "\r", "\x1a", "'"],
        ["\\\\", "\\0", "\\n", "\\r", "\\Z", "''"],
        (string) $value
    )."'";
}

function defaultValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    $value = (string) $value;

    if (preg_match('/^(NULL|CURRENT_TIMESTAMP|[-+]?[0-9]+(?:\.[0-9]+)?)$/i', $value)) {
        return ' DEFAULT '.$value;
    }

    if (strlen($value) >= 2 && $value[0] === "'" && $value[-1] === "'") {
        return ' DEFAULT '.mysqlValue(str_replace("''", "'", substr($value, 1, -1)));
    }

    return ' DEFAULT '.mysqlValue($value);
}

$tables = $db->query(
    "SELECT name FROM sqlite_master
     WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
     ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

fwrite($out, "-- Export MySQL generato da database SQLite\n");
fwrite($out, '-- Data: '.date(DATE_ATOM)."\n\n");
fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

foreach ($tables as $table) {
    $columns = $db->query('PRAGMA table_info('.ident($table).')')->fetchAll();
    $foreignKeys = $db->query('PRAGMA foreign_key_list('.ident($table).')')->fetchAll();
    $primaryColumns = [];
    $definitions = [];

    foreach ($columns as $column) {
        if ((int) $column['pk'] > 0) {
            $primaryColumns[(int) $column['pk']] = $column['name'];
        }
    }
    ksort($primaryColumns);

    foreach ($columns as $column) {
        $definition = ident($column['name']).' '.mysqlType($column['type']);
        $singleAutoIncrement = count($primaryColumns) === 1
            && reset($primaryColumns) === $column['name']
            && str_contains(strtoupper($column['type']), 'INT');

        if ((int) $column['notnull'] === 1 || $singleAutoIncrement) {
            $definition .= ' NOT NULL';
        } else {
            $definition .= ' NULL';
        }

        if (! $singleAutoIncrement) {
            $definition .= defaultValue($column['dflt_value']);
        }

        if ($singleAutoIncrement) {
            $definition .= ' AUTO_INCREMENT';
        }

        $definitions[] = $definition;
    }

    if ($primaryColumns !== []) {
        $definitions[] = 'PRIMARY KEY ('.implode(', ', array_map('ident', $primaryColumns)).')';
    }

    foreach ($foreignKeys as $foreignKey) {
        $definitions[] = sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s',
            ident(mysqlName('fk_'.$table.'_'.$foreignKey['from'])),
            ident($foreignKey['from']),
            ident($foreignKey['table']),
            ident($foreignKey['to']),
            $foreignKey['on_update'],
            $foreignKey['on_delete']
        );
    }

    fwrite($out, 'DROP TABLE IF EXISTS '.ident($table).";\n");
    fwrite(
        $out,
        'CREATE TABLE '.ident($table)." (\n  ".implode(",\n  ", $definitions).
        "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n"
    );
}

foreach ($tables as $table) {
    $indexes = $db->query('PRAGMA index_list('.ident($table).')')->fetchAll();

    foreach ($indexes as $index) {
        if (str_starts_with($index['name'], 'sqlite_autoindex_')) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }
            $name = 'uniq_'.$table.'_'.substr(sha1($index['name']), 0, 8);
        } else {
            $name = $index['name'];
        }

        $indexColumns = $db->query('PRAGMA index_info('.ident($index['name']).')')->fetchAll();
        $columnNames = array_column($indexColumns, 'name');

        if ($columnNames === []) {
            continue;
        }

        $unique = (int) $index['unique'] === 1 ? 'UNIQUE ' : '';
        fwrite(
            $out,
            'CREATE '.$unique.'INDEX '.ident(mysqlName($name)).' ON '.ident($table).
            ' ('.implode(', ', array_map('ident', $columnNames)).");\n"
        );
    }

    if ($indexes !== []) {
        fwrite($out, "\n");
    }
}

foreach ($tables as $table) {
    $columns = $db->query('PRAGMA table_info('.ident($table).')')->fetchAll();
    $columnNames = array_column($columns, 'name');
    $rows = $db->query('SELECT * FROM '.ident($table));
    $batch = [];

    while ($row = $rows->fetch()) {
        $batch[] = '('.implode(', ', array_map(mysqlValue(...), array_values($row))).')';

        if (count($batch) === 250) {
            fwrite(
                $out,
                'INSERT INTO '.ident($table).' ('.implode(', ', array_map('ident', $columnNames)).
                ") VALUES\n".implode(",\n", $batch).";\n"
            );
            $batch = [];
        }
    }

    if ($batch !== []) {
        fwrite(
            $out,
            'INSERT INTO '.ident($table).' ('.implode(', ', array_map('ident', $columnNames)).
            ") VALUES\n".implode(",\n", $batch).";\n"
        );
    }

    if ($rows->rowCount() > 0 || $batch !== []) {
        fwrite($out, "\n");
    }
}

fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($out);

echo "Export creato: {$target}\n";
