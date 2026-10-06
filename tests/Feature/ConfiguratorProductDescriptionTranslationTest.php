<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConfiguratorProductDescriptionTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_import_accepts_boundary_whitespace_and_preserves_original_html(): void
    {
        Http::preventStrayRequests();
        foreach ([
            'camera' => ['camara-ahd-1080p-para-renault-clio-5-2019-2022', 'camara-ahd-170-para-opel-insignia-2008-2019'],
            'screen' => ['pantalla-android-9-para-renault-megane-ii-2002-2009'],
        ] as $category => $handles) {
            foreach ($handles as $handle) {
                $catalog = json_decode(file_get_contents(resource_path("data/{$category}-descriptions-it.json")), true);
                $source = "\n".$catalog[$handle]['source']."\n\n";
                $product = ConfiguratorProduct::create([
                    'handle' => $handle,
                    'category' => $category,
                    'title' => 'Original title',
                    'title_it' => 'Titolo italiano',
                    'title_en' => 'English title',
                    'body_html' => $source,
                ]);
                foreach (['it', 'en'] as $locale) {
                    $this->artisan('configurator:translate-descriptions', [
                        'locale' => $locale,
                        '--category' => $category,
                        '--catalog-only' => true,
                    ])->assertSuccessful();
                    $catalog = json_decode(file_get_contents(resource_path("data/{$category}-descriptions-{$locale}.json")), true);
                    $this->assertSame($catalog[$handle]['translation'], $product->fresh()->{'body_html_'.$locale});
                    $this->assertSame($source, $product->fresh()->body_html);
                }
            }
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('postImportTasks.translationCount', 0)
                ->where('postImportTasks.descriptionTranslationCount', 0));
    }

    public function test_catalog_import_rejects_changed_specs(): void
    {
        Http::preventStrayRequests();
        $handle = 'camara-ahd-1080p-para-renault-clio-5-2019-2022';
        $catalog = json_decode(file_get_contents(resource_path('data/camera-descriptions-it.json')), true);
        $source = str_replace('170°', '120°', $catalog[$handle]['source']);
        $product = ConfiguratorProduct::create([
            'handle' => $handle,
            'category' => 'camera',
            'title' => 'Original title',
            'body_html' => $source,
        ]);
        foreach (['it', 'en'] as $locale) {
            $this->artisan('configurator:translate-descriptions', [
                'locale' => $locale,
                '--category' => 'camera',
                '--catalog-only' => true,
            ])->assertSuccessful();
            $this->assertNull($product->fresh()->{'body_html_'.$locale});
        }
        $this->assertSame($source, $product->fresh()->body_html);
    }
}
