<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DatabaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_admin_can_start_database_migrations(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('dashboard.database.migrate'))
            ->assertForbidden();
    }

    public function test_admin_can_start_database_migrations_from_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $lock = new class
        {
            public bool $released = false;

            public function get(): bool
            {
                return true;
            }

            public function release(): void
            {
                $this->released = true;
            }
        };

        Cache::shouldReceive('lock')->once()->with('admin:database-migration', 300)->andReturn($lock);
        Artisan::shouldReceive('call')->once()->with('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ])->andReturn(0);

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('dashboard.database.migrate'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertTrue($lock->released);
    }
}
