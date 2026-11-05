<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutogenesiMigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_recovers_partial_batch_table_without_losing_rows(): void
    {
        $migration = require database_path('migrations/2026_11_04_180000_create_autogenesi_batches_tables.php');
        $migration->down();
        $user = User::factory()->create();
        Schema::create('autogenesi_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('total');
            $table->timestamps();
        });
        $id = DB::table('autogenesi_batches')->insertGetId(['user_id' => $user->id, 'status' => 'paused', 'total' => 3]);
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('autogenesi_batches', ['id' => $id, 'user_id' => $user->id, 'status' => 'paused', 'total' => 3]);
        $this->assertTrue(Schema::hasTable('autogenesi_batch_items'));
        $this->assertTrue(Schema::hasForeignKey('autogenesi_batches', ['user_id']));
        $this->assertTrue(Schema::hasForeignKey('autogenesi_batch_items', ['batch_id']));
        $this->assertTrue(Schema::hasForeignKey('autogenesi_batch_items', ['product_id']));
    }

    public function test_user_column_matches_mysql_integer_size_and_unsigned_attribute(): void
    {
        $migration = require database_path('migrations/2026_11_04_180000_create_autogenesi_batches_tables.php');
        $method = new \ReflectionMethod($migration, 'userColumn');
        foreach (['int' => ['integer', false], 'int unsigned' => ['integer', true], 'bigint unsigned' => ['bigInteger', true], 'int(11)' => ['integer', false]] as $type => [$expected, $unsigned]) {
            Schema::shouldReceive('getColumnType')->with('users', 'id', true)->once()->andReturn($type);
            $table = new Blueprint(DB::connection(), 'autogenesi_batches');
            $column = $method->invoke($migration, $table);
            $this->assertSame($expected, $column->type);
            $this->assertSame($unsigned, $column->unsigned);
        }
    }
}
