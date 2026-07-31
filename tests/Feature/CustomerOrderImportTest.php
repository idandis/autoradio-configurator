<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_imports_matrixify_orders_and_derives_customer_order_date(): void
    {
        $csv = <<<'CSV'
ID,Name,Number,Processed At,Created At,Updated At,Currency,Price: Total Line Items,Price: Total Discount,Price: Total Shipping,Price: Total Refund,Price: Total Outstanding,Price: Current Total,Price: Total,Payment: Status,Order Fulfillment Status,Customer: ID,Customer: Email,Customer: First Name,Customer: Last Name,Customer: Orders Count,Customer: Total Spent,Shipping: Address 1,Shipping: Zip,Shipping: City,Shipping: Country,Line: Type,Line: ID,Line: Title,Line: SKU,Line: Quantity,Line: Price,Line: Discount,Line: Total,Transaction: ID,Transaction: Kind,Transaction: Amount,Fulfillment: ID,Fulfillment: Tracking Number
12345678901234,#1001,1001,2026-07-20 12:30:00 +0000,2026-07-20 12:30:00 +0000,2026-07-21 08:00:00 +0000,EUR,100,10,5,0,0,95,95,paid,fulfilled,99887766554433,mario@example.com,Mario,Rossi,2,195,Calle Uno 1,35001,Las Palmas,Spain,Line Item,5001,Autoradio,SKU-1,1,100,10,90,,,,
12345678901234,#1001,1001,2026-07-20 12:30:00 +0000,,,,,,,,,,,,,99887766554433,mario@example.com,Mario,Rossi,2,195,,,,,Transaction,,,,,,,,7001,sale,95,,
12345678901234,#1001,1001,2026-07-20 12:30:00 +0000,,,,,,,,,,,,,99887766554433,mario@example.com,Mario,Rossi,2,195,,,,,Fulfillment Line,,,,,,,,,,,8001,TRACK-1
CSV;

        $this
            ->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('customers.import-orders'), [
                'orders' => UploadedFile::fake()->createWithContent('Orders.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('customer_order_lines', 1);
        $this->assertDatabaseCount('customer_order_transactions', 1);
        $this->assertDatabaseCount('customer_order_fulfillments', 1);
        $this->assertDatabaseHas('customer_orders', [
            'shopify_id' => '12345678901234',
            'name' => '#1001',
            'total' => 95,
        ]);

        $customer = Customer::where('shopify_id', '99887766554433')->firstOrFail();
        $this->assertSame('2026-07-20 12:30:00', $customer->last_order_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $customer->orders()->count());
    }

    public function test_reimport_updates_the_order_without_duplicating_it(): void
    {
        $headers = 'ID,Name,Processed At,Customer: ID,Customer: Total Spent,Price: Total,Line: Type,Line: Title';
        $first = $headers."\n123,#1,2026-07-20,456,20,10,Line Item,Prodotto A\n";
        $second = $headers."\n123,#1,2026-07-21,456,30,15,Line Item,Prodotto B\n";
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ([$first, $second] as $csv) {
            $this->actingAs($admin)->post(route('customers.import-orders'), [
                'orders' => UploadedFile::fake()->createWithContent('Orders.csv', $csv),
            ])->assertRedirect();
        }

        $this->assertSame(1, CustomerOrder::count());
        $this->assertDatabaseHas('customer_orders', ['shopify_id' => '123', 'total' => 15]);
        $this->assertDatabaseHas('customer_order_lines', ['title' => 'Prodotto B']);
    }
}
