<?php

namespace Tests\Feature;

use App\Models\ConfigurationStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConfigurationStatisticTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_event_is_recorded_without_personal_data(): void
    {
        $payload = $this->payload();
        $payload['name'] = 'Must not be stored';
        $payload['email'] = 'private@example.com';
        $payload['phone'] = '+34000000000';

        $this->postJson('/configurator/statistics', $payload)->assertCreated();

        $statistic = ConfigurationStatistic::sole();
        $this->assertSame('checkout_clicked', $statistic->event_type);
        $this->assertSame('Toyota', $statistic->brand);
        $this->assertFalse(array_key_exists('email', $statistic->getAttributes()));
        $this->assertFalse(array_key_exists('phone', $statistic->getAttributes()));
    }

    public function test_same_event_and_configuration_is_deduplicated_for_ten_seconds(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $payload = $this->payload();

        $this->postJson('/configurator/statistics', $payload)->assertCreated();
        $this->postJson('/configurator/statistics', $payload)->assertNoContent();
        $this->assertDatabaseCount('configuration_statistics', 1);

        Carbon::setTestNow(now()->addSeconds(11));
        $this->postJson('/configurator/statistics', $payload)->assertCreated();
        $this->assertDatabaseCount('configuration_statistics', 2);
        Carbon::setTestNow();
    }

    private function payload(): array
    {
        return [
            'session_uuid' => '36f0b7b8-25ed-4ca8-a06a-71c453c9f31d',
            'event_type' => 'checkout_clicked',
            'brand' => 'Toyota',
            'model' => 'RAV4',
            'year' => 2004,
            'product_id' => 10,
            'variant_id' => 20,
            'product_title' => 'Pantalla RAV4',
            'variant_title' => '4Core 2G 64G',
            'product_price' => 219,
            'installation_selected' => true,
            'installation_type' => 'Instalación pantalla',
            'camera_selected' => false,
            'postal_code' => '35120',
            'service_zone' => 'south',
            'language' => 'es',
            'referrer' => 'https://example.com/configurator',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'summer',
            'device_type' => 'desktop',
        ];
    }
}
