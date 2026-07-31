<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_imports_only_customers_with_positive_spending(): void
    {
        $csv = <<<'CSV'
ID,Email,First Name,Last Name,Phone,Language,Total Spent,Total Orders,First Order: Processed At,Last Order: Processed At,Created At,Updated At,Address ID,Address First Name,Address Last Name,Address Phone,Address Company,Address Line 1,Address Line 2,Address City,Address Province,Address Province Code,Address Country,Address Country Code,Address Zip,Address Is Default
1001,maria@example.com,Maria,Rossi,+34111111111,es,349.90,2,2026-01-10,2026-07-20,2026-01-10,2026-07-20,5001,Maria,Rossi,+34111111111,,Calle Uno 10,,Las Palmas,Las Palmas,GC,Spain,ES,35001,TRUE
1001,,,,,,,,,,,,5002,Maria,Rossi,+34111111111,,Calle Due 20,,Telde,Las Palmas,GC,Spain,ES,35200,FALSE
1002,empty@example.com,Cliente,Senza ordini,,it,0,0,2026-02-01,2026-02-01,,,,,,,,,,,,,,
1003,spent@example.com,Cliente,Con spesa,,en,10.50,0,2026-03-01,2026-03-01,,,,,,,,,,,,,,
CSV;

        $response = $this
            ->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('customers.import'), [
                'customers' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseHas('customers', [
            'shopify_id' => '1001',
            'email' => 'maria@example.com',
            'total_orders' => 2,
            'total_spent' => 349.90,
            'first_order_at' => '2026-01-10 00:00:00',
            'last_order_at' => '2026-07-20 00:00:00',
        ]);
        $this->assertDatabaseHas('customers', [
            'shopify_id' => '1003',
            'total_orders' => 0,
            'total_spent' => 10.50,
        ]);
        $this->assertDatabaseMissing('customers', ['shopify_id' => '1002']);
        $this->assertSame(2, Customer::where('shopify_id', '1001')->firstOrFail()->addresses()->count());
    }

    public function test_reimport_updates_customer_without_duplication(): void
    {
        Customer::create([
            'shopify_id' => '1001',
            'email' => 'old@example.com',
            'first_name' => 'Old',
            'total_orders' => 1,
            'total_spent' => 20,
        ]);

        $csv = <<<'CSV'
ID,Email,First Name,Last Name,Total Spent,Total Orders
1001,new@example.com,Nuovo,Nome,99.90,3
CSV;

        $this
            ->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('customers.import'), [
                'customers' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'shopify_id' => '1001',
            'email' => 'new@example.com',
            'first_name' => 'Nuovo',
            'total_orders' => 3,
            'total_spent' => 99.90,
        ]);
    }

    public function test_non_admin_cannot_import_customers(): void
    {
        $this
            ->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('customers.import'))
            ->assertForbidden();
    }
}
