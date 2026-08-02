<?php

namespace Tests\Feature;

use App\Models\SharedConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_public_visitor_can_open_shared_configuration(): void
    {
        $configuration = [
            'brand' => 'Toyota',
            'model' => 'RAV4',
            'year' => 2004,
            'screens' => [[
                'product' => 'android-rav4',
                'variant' => '123456789',
            ]],
            'cameras' => ['camera-rav4'],
            'speakers' => [],
            'customProducts' => [],
            'installation' => 'installation-screen',
            'postalCode' => '35120',
            'serviceZone' => 'south',
            'precheck' => 'self',
        ];
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->postJson('/configurator/shared-configurations', compact('configuration'))
            ->assertCreated()
            ->assertJsonStructure(['uuid']);

        $uuid = $response->json('uuid');
        $this->assertDatabaseHas('shared_configurations', ['uuid' => $uuid]);

        $this->get('/configurator?c='.$uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('sharedConfiguration', $configuration)
            );
    }

    public function test_invalid_or_unknown_uuid_does_not_restore_a_configuration(): void
    {
        $this->get('/configurator?c=not-a-uuid')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('sharedConfiguration', null)
            );
    }

    public function test_prune_command_deletes_only_shared_configurations_older_than_30_days(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');

        $expired = SharedConfiguration::create([
            'uuid' => fake()->uuid(),
            'configuration' => ['screens' => []],
        ]);
        $expired->forceFill([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ])->saveQuietly();

        $current = SharedConfiguration::create([
            'uuid' => fake()->uuid(),
            'configuration' => ['screens' => []],
        ]);

        $this->artisan('shared-configurations:prune')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($current);
        Carbon::setTestNow();
    }
}
