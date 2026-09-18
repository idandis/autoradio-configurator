<?php

namespace Tests\Feature;

use App\Http\Controllers\ItalianCheckoutMaintenanceController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ItalianCheckoutMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_non_admin_cannot_update_checkout(): void
    {
        Artisan::shouldReceive('call')->never();
        $this->post(route('dashboard.italian-checkout.update'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('dashboard.italian-checkout.update'))->assertForbidden();
    }

    public function test_admin_runs_only_checkout_migrations_and_defers_checks_until_reload(): void
    {
        $lock = Cache::lock('admin:database-migration', 300);
        Artisan::shouldReceive('call')->once()->with('migrate', [
            '--path' => ItalianCheckoutMaintenanceController::MIGRATIONS,
            '--force' => true,
            '--no-interaction' => true,
        ])->andReturn(0);
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            Artisan::shouldReceive('call')->once()->with($command, ['--no-interaction' => true])->andReturn(0);
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('dashboard.italian-checkout.update'), ['command' => 'migrate:fresh', 'path' => 'other'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('italian_checkout_check_requested', true);
        $this->assertTrue($lock->get(), 'Maintenance must release the shared migration lock.');
        $lock->release();
    }

    public function test_general_database_lock_prevents_parallel_checkout_migration(): void
    {
        $lock = Cache::lock('admin:database-migration', 300);
        $this->assertTrue($lock->get());
        Artisan::shouldReceive('call')->never();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->from(route('dashboard'))->post(route('dashboard.italian-checkout.update'))
            ->assertSessionHasErrors('italian_checkout');
        $this->assertFalse(Cache::lock('admin:database-migration', 300)->get());
        $lock->release();
    }

    public function test_failed_migration_releases_lock_and_does_not_report_success(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate', \Mockery::type('array'))->andReturn(1);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->from(route('dashboard'))->post(route('dashboard.italian-checkout.update'))
            ->assertSessionHasErrors('italian_checkout')
            ->assertSessionMissing('italian_checkout_check_requested');
        $lock = Cache::lock('admin:database-migration', 300);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_dashboard_shows_configuration_results_without_repeating_stripe_checks_on_refresh(): void
    {
        config(['stripe.mode' => 'test', 'stripe.key' => 'pk_test_fake', 'stripe.secret' => 'sk_test_fake']);
        Artisan::shouldReceive('call')->once()->with('italian-payments:check', [
            '--stripe' => true, '--no-interaction' => true,
        ])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn("OK · Database\nNO · Conferme email\n");
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->withSession(['italian_checkout_check_requested' => true])
            ->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('italianCheckoutReport.passed', false)
            ->where('italianCheckoutReport.lines', ['OK · Database', 'NO · Conferme email']));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('italianCheckoutReport.passed', false));
    }
}
