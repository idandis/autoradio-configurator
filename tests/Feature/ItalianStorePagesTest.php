<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockOutsideEurope;
use App\Models\ConfiguratorProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ItalianStorePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_italian_information_pages_are_public_and_localized(): void
    {
        $this->withoutMiddleware(BlockOutsideEurope::class);
        $pages = json_decode(file_get_contents(resource_path('data/italian-store-pages.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($pages as $slug => $content) {
            $this->get('https://www.autoradioitaliano.it/'.$slug)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('ItalianStorePage')
                    ->where('slug', $slug)->where('page.title', $content['title'])
                    ->where('page.sections', $content['sections']))
                ->assertSessionHas('locale', 'it');
        }
        $this->get('/pagina-inesistente')->assertNotFound();
    }

    public function test_brand_listing_uses_catalog_compatibility_without_restoring_a_previous_mode(): void
    {
        $this->withoutMiddleware(BlockOutsideEurope::class);
        ConfiguratorProduct::create([
            'handle' => 'autoradio-compatibile', 'category' => 'screen', 'title' => 'Autoradio',
            'brand' => 'FIAT', 'model' => '500', 'year_from' => 2007, 'year_to' => 2015,
        ]);
        $this->get('/marche?lang=es')->assertRedirect('/configurator?lang=it&mode=specific&pick=brand');
        $this->get('/configurator?lang=it&mode=specific&pick=brand')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurator')->where('locale', 'it')
            ->where('vehicleCompatibility.0.brand', 'FIAT')->missing('brandListing')->missing('homeBeta'));
    }
}
