<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('autogenesi_batches')) Schema::create('autogenesi_batches', function (Blueprint $table) {
            $table->id();
            $this->userColumn($table);
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('total');
            $table->timestamps();
        });
        if (! Schema::hasForeignKey('autogenesi_batches', ['user_id'])) {
            Schema::table('autogenesi_batches', function (Blueprint $table) {
                $this->userColumn($table)->change();
            });
            Schema::table('autogenesi_batches', fn (Blueprint $table) => $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete());
        }
        if (! Schema::hasTable('autogenesi_batch_items')) Schema::create('autogenesi_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('autogenesi_batches')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('query', 240);
            $table->json('vehicle');
            $table->string('status')->default('queued')->index();
            $table->text('source_url')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('autogenesi_products')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'position']);
        });
        if (! Schema::hasForeignKey('autogenesi_batch_items', ['batch_id'])) {
            Schema::table('autogenesi_batch_items', fn (Blueprint $table) => $table->foreign('batch_id')->references('id')->on('autogenesi_batches')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('autogenesi_batch_items', ['product_id'])) {
            Schema::table('autogenesi_batch_items', fn (Blueprint $table) => $table->foreign('product_id')->references('id')->on('autogenesi_products')->nullOnDelete());
        }
    }

    private function userColumn(Blueprint $table): \Illuminate\Database\Schema\ColumnDefinition
    {
        $type = strtolower(Schema::getColumnType('users', 'id', true));
        if (! preg_match('/^(tinyint|smallint|mediumint|bigint|int|integer)(?:\(\d+\))?(?:\s+unsigned)?$/', $type, $match)) {
            throw new RuntimeException('Tipo users.id non supportato: '.$type);
        }
        $method = match ($match[1]) {
            'tinyint' => 'tinyInteger', 'smallint' => 'smallInteger', 'mediumint' => 'mediumInteger', 'bigint' => 'bigInteger', default => 'integer',
        };

        return $table->{$method}('user_id')->unsigned(str_contains($type, 'unsigned'));
    }

    public function down(): void
    {
        Schema::dropIfExists('autogenesi_batch_items');
        Schema::dropIfExists('autogenesi_batches');
    }
};
