<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfiguratorProductTitleTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_uses_only_the_translation_for_the_active_locale(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'radio-test',
            'category' => 'screen',
            'title' => 'Radio para coche',
            'title_it' => 'Autoradio per auto',
            'title_en' => 'Car radio',
        ]);

        $this->assertSame('Radio para coche', $product->localizedTitle('es'));
        $this->assertSame('Autoradio per auto', $product->localizedTitle('it'));
        $this->assertSame('Car radio', $product->localizedTitle('en'));

        $product->update(['title_en' => null]);
        $this->assertSame('Radio para coche', $product->fresh()->localizedTitle('en'));
    }

    public function test_admin_can_edit_internal_title_translations(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'radio-test',
            'category' => 'screen',
            'title' => 'Radio para coche',
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->patch(route('imported-products.titles', $product), [
                'title_it' => 'Autoradio per auto',
                'title_en' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('configurator_products', [
            'id' => $product->id,
            'title' => 'Radio para coche',
            'title_it' => 'Autoradio per auto',
            'title_en' => null,
        ]);
    }

    public function test_translation_command_populates_only_missing_italian_titles(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        ConfiguratorProduct::create([
            'handle' => 'radio-test',
            'category' => 'screen',
            'title' => 'Radio Android para Fiat Panda',
        ]);
        ConfiguratorProduct::create([
            'handle' => 'already-translated',
            'category' => 'screen',
            'title' => 'Radio para Ford Focus',
            'title_it' => 'Titolo corretto manualmente',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'translations' => ['1' => 'Autoradio Android per Fiat Panda'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->artisan('configurator:translate-titles', ['locale' => 'it'])
            ->assertSuccessful();

        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'radio-test',
            'title_it' => 'Autoradio Android per Fiat Panda',
        ]);
        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'already-translated',
            'title_it' => 'Titolo corretto manualmente',
        ]);
    }
}
