<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_language_is_saved_in_session(): void
    {
        $this->get('/?lang=it')
            ->assertSessionHas('locale', 'it');
    }

    public function test_spanish_is_used_by_default(): void
    {
        $this->get('/configurator')
            ->assertSessionHas('locale', 'es')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('locale', 'es')
                ->where('translations.steps.vehicle', '1. Elige tu coche')
            );

        $this->get('/?lang=es')
            ->assertSessionHas('locale', 'es');
    }

    public function test_unsupported_language_is_ignored(): void
    {
        $this->withSession(['locale' => 'en'])
            ->get('/?lang=fr')
            ->assertSessionHas('locale', 'en');
    }

    public function test_configurator_receives_the_selected_translation_dictionary(): void
    {
        $this->get('/configurator?lang=en')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('locale', 'en')
                ->where('translations.steps.vehicle', '1. Choose your car')
            );

        $this->get('/configurator?lang=it')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('locale', 'it')
                ->where('translations.steps.vehicle', '1. Scegli la tua auto')
            );
    }

    public function test_language_is_detected_from_the_storefront_referrer(): void
    {
        $this->withSession(['locale' => 'es'])
            ->withHeader('Referer', 'https://www.autoradiocanario.com/it/products/autoradio')
            ->get('/configurator')
            ->assertSessionHas('locale', 'it')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));

        $this->withHeader('Referer', 'https://www.autoradiocanario.com/en')
            ->get('/configurator')
            ->assertSessionHas('locale', 'en');

        $this->withHeader('Referer', 'https://www.autoradiocanario.com/products/autoradio')
            ->get('/configurator')
            ->assertSessionHas('locale', 'es');
    }

    public function test_query_language_has_priority_over_storefront_referrer(): void
    {
        $this->withHeader('Referer', 'https://www.autoradiocanario.com/it')
            ->get('/configurator?lang=en')
            ->assertSessionHas('locale', 'en');
    }

    public function test_untrusted_referrer_does_not_change_the_session_language(): void
    {
        $this->withSession(['locale' => 'it'])
            ->withHeader('Referer', 'https://example.com/en')
            ->get('/configurator')
            ->assertSessionHas('locale', 'it');
    }

    public function test_browser_language_is_used_on_the_first_visit(): void
    {
        $this->withHeader('Accept-Language', 'it-IT,it;q=0.9,en;q=0.8')
            ->get('/configurator')
            ->assertSessionHas('locale', 'it')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));

        $this->flushSession();

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get('/configurator')
            ->assertSessionHas('locale', 'en');

        $this->flushSession();

        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->get('/configurator')
            ->assertSessionHas('locale', 'es');
    }
}
