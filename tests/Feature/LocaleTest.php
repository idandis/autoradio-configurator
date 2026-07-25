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
            ->assertSessionMissing('locale')
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
}
