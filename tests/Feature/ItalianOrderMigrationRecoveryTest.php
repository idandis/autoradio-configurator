<?php

namespace Tests\Feature;

use App\Http\Controllers\ItalianCheckoutMaintenanceController;
use App\Models\ItalianOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItalianOrderMigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ItalianOrder
    {
        return ItalianOrder::create([
            'customer_name' => 'Ordine da conservare', 'email' => 'customer@example.test',
            'shipping_address' => ['country' => 'IT'], 'subtotal_amount' => 10000,
            'total_amount' => 10000, 'payment_status' => 'paid', 'paid_at' => now(),
            'is_test' => false, 'internal_notes' => 'Non eliminare',
        ])->refresh();
    }

    private function forgetMigrationRecords(): void
    {
        DB::table('migrations')->whereIn('migration', array_map(
            fn ($path) => basename($path, '.php'), ItalianCheckoutMaintenanceController::MIGRATIONS,
        ))->delete();
    }

    private function resume(): void
    {
        $this->artisan('migrate', [
            '--path' => ItalianCheckoutMaintenanceController::MIGRATIONS, '--force' => true,
        ])->assertSuccessful();
    }

    public function test_existing_tables_without_migration_records_keep_all_order_data(): void
    {
        $order = $this->order();
        $before = $order->getAttributes();
        $this->forgetMigrationRecords();
        $this->resume();
        $this->resume();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(6, DB::table('migrations')->whereIn('migration', array_map(
            fn ($path) => basename($path, '.php'), ItalianCheckoutMaintenanceController::MIGRATIONS,
        ))->count());
    }

    public function test_partially_created_base_tables_are_completed_without_recreating_orders(): void
    {
        $order = $this->order();
        Schema::drop('italian_order_events');
        Schema::drop('italian_order_items');
        $this->forgetMigrationRecords();
        $this->resume();
        $this->assertTrue(Schema::hasTable('italian_order_items'));
        $this->assertTrue(Schema::hasColumn('italian_order_events', 'kind'));
        $this->assertTrue(Schema::hasForeignKey('italian_order_events', ['user_id']));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('Non eliminare', $order->fresh()->internal_notes);
    }

    public function test_missing_index_and_foreign_key_from_interrupted_ddl_are_restored(): void
    {
        $order = $this->order();
        Schema::table('italian_orders', fn (Blueprint $table) => $table->dropUnique(['checkout_token']));
        Schema::table('italian_order_events', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        $this->forgetMigrationRecords();
        $this->resume();
        $this->assertTrue(Schema::hasIndex('italian_orders', ['checkout_token'], 'unique'));
        $this->assertTrue(Schema::hasForeignKey('italian_order_events', ['user_id']));
        $this->assertSame(10000, $order->fresh()->total_amount);
    }

    public function test_partial_column_migration_can_be_repeated(): void
    {
        $order = $this->order();
        Schema::table('italian_orders', fn (Blueprint $table) => $table->dropColumn('deleted_at'));
        Schema::table('italian_order_events', fn (Blueprint $table) => $table->dropColumn('kind'));
        $this->forgetMigrationRecords();
        $this->resume();
        $this->assertTrue(Schema::hasColumn('italian_orders', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('italian_order_events', 'kind'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }
}
