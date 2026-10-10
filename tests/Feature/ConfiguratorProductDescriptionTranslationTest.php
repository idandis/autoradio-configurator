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

    public function test_dashboard_imports_juke_and_i20_descriptions_and_preserves_originals(): void
    {
        Http::preventStrayRequests();
        config(['services.openai.api_key' => null]);
        $handles = ['2', 'autoradio-android-13-qled-9-con-carplay-y-android-auto-para-hyundai-i20-2014-2018-navegador-gps-wifi-control-volante-4gb-ram-128gb-rom'];
        $catalogs = [];
        foreach (['it', 'en'] as $locale) {
            $catalogs[$locale] = json_decode(file_get_contents(resource_path("data/screen-descriptions-{$locale}.json")), true);
        }
        foreach ($handles as $handle) {
            ConfiguratorProduct::create([
                'handle' => $handle, 'category' => 'screen', 'title' => 'Original title',
                'title_it' => 'Titolo esistente', 'title_en' => 'Existing title', 'body_html' => $catalogs['it'][$handle]['source'],
            ]);
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('dashboard.database.migrate'))->assertRedirect()->assertSessionHasNoErrors();
        foreach ($handles as $handle) {
            $product = ConfiguratorProduct::where('handle', $handle)->firstOrFail();
            $this->assertSame($catalogs['it'][$handle]['source'], $product->body_html);
            $this->assertSame('Original title', $product->title);
            $this->assertSame('Titolo esistente', $product->title_it);
            foreach (['it', 'en'] as $locale) {
                $translation = $catalogs[$locale][$handle]['translation'];
                $this->assertSame($translation, $product->{'body_html_'.$locale});
                preg_match_all('/<[^>]*>/', $product->body_html, $sourceTags);
                preg_match_all('/<[^>]*>/', $translation, $translatedTags);
                $this->assertSame($sourceTags[0], $translatedTags[0]);
            }
        }
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('postImportTasks.translationCount', 0)
            ->where('postImportTasks.descriptionTranslationCount', 0));
    }

    public function test_dashboard_update_imports_accessory_translations_without_external_requests(): void
    {
        Http::preventStrayRequests();
        config(['services.openai.api_key' => null]);
        $handle = 'adaptador-usb-hdmi-para-autoradios-android-compatibles';
        $titles = json_decode(file_get_contents(resource_path('data/accessory-titles-it.json')), true);
        $descriptions = json_decode(file_get_contents(resource_path('data/accessory-descriptions-it.json')), true);
        $product = ConfiguratorProduct::create([
            'handle' => $handle, 'category' => 'accessory', 'title' => $titles[$handle]['source'],
            'body_html' => $descriptions[$handle]['source'],
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
                ->where('postImportTasks.translationCount', 0)
                ->where('postImportTasks.descriptionTranslationCount', 0));
        $this->post(route('dashboard.database.migrate'))->assertRedirect()->assertSessionHasNoErrors();
        $product->refresh();
        $this->assertSame($titles[$handle]['source'], $product->title);
        $this->assertSame($descriptions[$handle]['source'], $product->body_html);
        foreach (['it', 'en'] as $locale) {
            foreach (['titles' => 'title_', 'descriptions' => 'body_html_'] as $kind => $column) {
                $catalog = json_decode(file_get_contents(resource_path("data/accessory-{$kind}-{$locale}.json")), true);
                $this->assertSame($catalog[$handle]['translation'], $product->{$column.$locale});
            }
        }
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('postImportTasks.translationCount', 0)
            ->where('postImportTasks.descriptionTranslationCount', 0));
    }

    public function test_dashboard_update_imports_post_import_catalogs_and_clears_translation_tasks(): void
    {
        Http::preventStrayRequests();
        config(['services.openai.api_key' => null]);
        $titles = json_decode(file_get_contents(resource_path('data/screen-titles-it.json')), true);
        $descriptions = json_decode(file_get_contents(resource_path('data/screen-descriptions-it.json')), true);
        $handles = [
            'autoradio-9-2din-carplay-para-vw-amarok-2017-2021',
            'autoradio-android-9-2-din-volvo-c30-s40-v50-c70-2004-2012',
            'pantalla-tesla-9-7-2-din-android-vw-amarok-crafter-2017-2035',
        ];
        foreach ($handles as $handle) {
            ConfiguratorProduct::create([
                'handle' => $handle, 'category' => 'screen', 'title' => $titles[$handle]['source'],
                'title_it' => '', 'body_html' => $descriptions[$handle]['source'],
            ]);
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('dashboard.database.migrate'))->assertRedirect()->assertSessionHasNoErrors();
        foreach ($handles as $handle) {
            $product = ConfiguratorProduct::where('handle', $handle)->firstOrFail();
            $this->assertSame($titles[$handle]['source'], $product->title);
            $this->assertSame($descriptions[$handle]['source'], $product->body_html);
            foreach (['it', 'en'] as $locale) {
                foreach (['titles' => 'title_', 'descriptions' => 'body_html_'] as $kind => $column) {
                    $catalog = json_decode(file_get_contents(resource_path("data/screen-{$kind}-{$locale}.json")), true);
                    $this->assertSame($catalog[$handle]['translation'], $product->{$column.$locale});
                }
            }
        }
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('postImportTasks.translationCount', 0)
            ->where('postImportTasks.descriptionTranslationCount', 0));
    }

    public function test_reimport_preserves_translations_until_the_source_html_changes(): void
    {
        Http::preventStrayRequests();
        $headers = (new \ReflectionClass(\App\Services\ConfiguratorCsvImporter::class))->getConstant('REQUIRED_HEADERS');
        $headers[] = 'Body (HTML)';
        $source = '<p>Descripción <strong>4 GB</strong></p>';
        $product = ConfiguratorProduct::create([
            'handle' => 'html-reimport', 'category' => 'screen', 'title' => 'Radio original',
            'title_it' => 'Radio italiana', 'title_en' => 'English radio',
            'body_html' => $source, 'body_html_it' => '<p>Descrizione <strong>4 GB</strong></p>',
            'body_html_en' => '<p>Description <strong>4 GB</strong></p>',
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([$source, '<p>Descripción <strong>8 GB</strong></p>'] as $html) {
            $row = array_replace(array_fill_keys($headers, ''), [
                'Handle' => 'html-reimport', 'Title' => 'Radio original', 'Type' => 'Radio AM/FM',
                'Variant ID' => '987654', 'Variant Price' => '49.00', 'Body (HTML)' => $html,
                'Metafield: custom.modello_auto [single_line_text_field]' => 'Universal',
            ]);
            $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('reimport.csv', implode(',', $headers)."\n".implode(',', $row));
            app(\App\Services\ConfiguratorCsvImporter::class)->import($file, replaceExistingDataset: false);
            $product->refresh();
            $this->assertSame($html, $product->body_html);
            $this->assertSame('Radio italiana', $product->title_it);
            $this->assertSame('English radio', $product->title_en);
            if ($html === $source) {
                $this->assertSame('<p>Descrizione <strong>4 GB</strong></p>', $product->body_html_it);
                $this->assertSame('<p>Description <strong>4 GB</strong></p>', $product->body_html_en);
            } else {
                $this->assertNull($product->body_html_it);
                $this->assertNull($product->body_html_en);
            }
        }
    }

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
