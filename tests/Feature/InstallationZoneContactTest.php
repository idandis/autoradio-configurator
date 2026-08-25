<?php

namespace Tests\Feature;

use App\Models\InstallationZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationZoneContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_installer_contact_details_for_a_zone(): void
    {
        $zone = InstallationZone::create(['name' => 'Madrid', 'active' => true]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('installation-zones.update', $zone), [
                'name' => 'Madrid',
                'installer_address' => 'Calle Mayor 10, Madrid',
                'installer_phone' => '+34 600 123 456',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Zona aggiornata.');

        $this->assertDatabaseHas('installation_zones', [
            'id' => $zone->id,
            'installer_address' => 'Calle Mayor 10, Madrid',
            'installer_phone' => '+34 600 123 456',
        ]);
    }

    public function test_postal_code_lookup_returns_installer_contact_details(): void
    {
        $zone = InstallationZone::create([
            'name' => 'Madrid',
            'installer_address' => 'Calle Mayor 10, Madrid',
            'installer_phone' => '+34 600 123 456',
            'active' => true,
        ]);
        $zone->postalCodes()->create([
            'postal_code_from' => '28001',
            'postal_code_to' => '28020',
        ]);
        $zone->services()->create(['name' => 'Installazione', 'price' => 100]);

        $this->getJson(route('configurator.postal-code', '28010'))
            ->assertOk()
            ->assertJsonPath('installationArea.installerAddress', 'Calle Mayor 10, Madrid')
            ->assertJsonPath('installationArea.installerPhone', '+34 600 123 456');
    }
}
