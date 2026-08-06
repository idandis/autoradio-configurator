<?php

namespace Tests\Feature;

use App\Models\ConfigurationStatistic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_visitor_is_recorded_once_with_proxy_geography_and_without_ip(): void
    {
        $payload = [
            'session_uuid' => '46f0b7b8-25ed-4ca8-a06a-71c453c9f31d',
            'event_type' => 'configurator_entered',
            'installation_selected' => false,
            'camera_selected' => false,
            'language' => 'it',
            'device_type' => 'mobile',
        ];
        $headers = [
            'CF-IPCountry' => 'ES',
            'X-Vercel-IP-Country-Region' => 'CN',
            'X-Vercel-IP-City' => 'Las%20Palmas',
        ];

        $this->withHeaders($headers)->postJson('/configurator/statistics', $payload)->assertCreated();
        $this->withHeaders($headers)->postJson('/configurator/statistics', $payload)->assertNoContent();

        $visitor = ConfigurationStatistic::sole();
        $this->assertSame('ES', $visitor->country_code);
        $this->assertSame('CN', $visitor->region);
        $this->assertSame('Las Palmas', $visitor->city);
        $this->assertArrayNotHasKey('ip', $visitor->getAttributes());
    }

    public function test_visitor_dashboard_is_admin_only_and_has_visitor_statistics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        ConfigurationStatistic::create([
            'session_uuid' => '56f0b7b8-25ed-4ca8-a06a-71c453c9f31d',
            'event_type' => 'configurator_entered',
            'installation_selected' => false,
            'camera_selected' => false,
            'country_code' => 'ES',
            'city' => 'Telde',
            'device_type' => 'mobile',
        ]);

        $this->actingAs($nonAdmin)->get('/visitor-statistics')->assertForbidden();
        $this->actingAs($admin)->get('/visitor-statistics')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('VisitorStatistics')
            ->has('visitors.data', 1)
            ->where('stats.total', 1)
            ->where('analysis.countries.0.label', 'ES')
            ->where('analysis.countries.0.value', 1)
        );
    }

    public function test_admin_can_filter_paginated_statistics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ConfigurationStatistic::create($this->payload());
        ConfigurationStatistic::create([
            ...$this->payload(),
            'session_uuid' => '9af68be8-0c9a-4747-b5dc-924701e54db0',
            'event_type' => 'quote_downloaded',
            'brand' => null,
            'model' => null,
            'year' => null,
            'product_title' => 'Altoparlante universale',
            'language' => 'it',
        ]);

        $this->actingAs($admin)
            ->get('/configuration-statistics?event_type=quote_downloaded&language=it&search=Altoparlante')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ConfigurationStatistics')
                ->has('events.data', 1)
                ->where('events.per_page', 50)
                ->where('stats.checkout_clicked', 1)
                ->where('stats.quote_downloaded', 1)
                ->where('stats.total', 2)
            );
    }

    public function test_admin_can_delete_one_or_selected_statistics_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $first = ConfigurationStatistic::create($this->payload());
        $second = ConfigurationStatistic::create([...$this->payload(), 'session_uuid' => '10f68be8-0c9a-4747-b5dc-924701e54db0']);
        $third = ConfigurationStatistic::create([...$this->payload(), 'session_uuid' => '20f68be8-0c9a-4747-b5dc-924701e54db0']);

        $this->actingAs($admin)->delete(route('configuration-statistics.destroy', $first))->assertRedirect();
        $this->assertDatabaseMissing('configuration_statistics', ['id' => $first->id]);
        $this->assertDatabaseHas('configuration_statistics', ['id' => $second->id]);

        $this->actingAs($admin)->delete(route('configuration-statistics.destroy-selected'), [
            'ids' => [$second->id],
        ])->assertRedirect();
        $this->assertDatabaseMissing('configuration_statistics', ['id' => $second->id]);
        $this->assertDatabaseHas('configuration_statistics', ['id' => $third->id]);
    }

    public function test_complete_deletion_requires_exact_confirmation_and_admin_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        ConfigurationStatistic::create($this->payload());

        $this->actingAs($nonAdmin)->delete(route('configuration-statistics.destroy-all'), [
            'confirmation' => 'CANCELLA',
        ])->assertForbidden();
        $this->assertDatabaseCount('configuration_statistics', 1);

        $this->actingAs($admin)->delete(route('configuration-statistics.destroy-all'), [
            'confirmation' => 'cancella',
        ])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('configuration_statistics', 1);

        $this->actingAs($admin)->delete(route('configuration-statistics.destroy-all'), [
            'confirmation' => 'CANCELLA',
        ])->assertRedirect();
        $this->assertDatabaseCount('configuration_statistics', 0);
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
