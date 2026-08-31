<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EuropeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_european_country_can_access_the_configurator(): void
    {
        $this->withHeader('CF-IPCountry', 'ES')
            ->get('/configurator')
            ->assertOk();
    }

    public function test_country_outside_europe_can_access_and_is_tracked_separately(): void
    {
        $this->withHeader('CF-IPCountry', 'US')
            ->get('/configurator')
            ->assertOk();

        $this->assertDatabaseHas('extra_eu_visitors', [
            'country_code' => 'US',
            'requested_path' => '/configurator',
            'hits' => 1,
        ]);
    }

    public function test_extra_eu_visit_is_excluded_from_regular_statistics(): void
    {
        $this->withHeader('CF-IPCountry', 'US')
            ->withHeader('User-Agent', 'Mozilla/5.0 Safari/605.1.15')
            ->postJson('/configurator/statistics', [
                'session_uuid' => '46f0b7b8-25ed-4ca8-a06a-71c453c9f31d',
                'event_type' => 'configurator_entered',
                'installation_selected' => false,
                'camera_selected' => false,
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('configuration_statistics', 0);
        $this->assertDatabaseCount('extra_eu_visitors', 1);
    }

    public function test_unknown_country_is_not_blocked(): void
    {
        $this->get('/configurator')->assertOk();
    }
}
