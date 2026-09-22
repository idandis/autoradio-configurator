<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConfiguratorCheckoutRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_is_available_on_all_sites_regardless_of_language(): void
    {
        config(['italian_checkout.enabled' => true]);

        foreach (['autoradioitaliano.it', 'www.autoradioitaliano.it', 'autoradiocanario.com', 'www.autoradiocanario.com', 'config.autoradiocanario.com', 'localhost'] as $host) {
            foreach (['it', 'es', 'en'] as $locale) {
                $this->get("https://{$host}/configurator?lang={$locale}")
                    ->assertOk()
                    ->assertInertia(fn (Assert $page) => $page
                        ->component('Configurator')
                        ->where('italianCheckoutEnabled', true));
            }
        }
    }

    public function test_disabled_stripe_is_reported_as_unavailable(): void
    {
        config(['italian_checkout.enabled' => false]);

        $this->get('https://www.autoradioitaliano.it/configurator')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('italianCheckoutEnabled', false));
    }
}
