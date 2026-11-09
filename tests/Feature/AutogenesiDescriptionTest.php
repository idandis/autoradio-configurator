<?php

namespace Tests\Feature;

use App\Services\AutogenesiDescription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiDescriptionTest extends TestCase
{
    private string $dir;

    private array $source;

    private array $copy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.openai.api_key' => 'test-key', 'services.openai.text_model' => 'gpt-5-mini']);
        Http::preventStrayRequests();
        $this->dir = sys_get_temp_dir().'/autogenesi-copy-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->dir);
        $photo = UploadedFile::fake()->image('photo.png', 300, 300);
        foreach (['image-1.bin', 'description-1.bin'] as $name) {
            copy($photo->getRealPath(), $this->dir.'/'.$name);
        }
        $this->source = ['source_title' => 'Radio Hyundai Kona', 'source_text' => 'AI summary: unsupported 360 kit included',
            'variants' => [['label' => '4GB/64GB', 'supplier_price' => '300']], 'images' => [['file' => 'image-1.bin']],
            'description' => ['complete' => true, 'text' => 'WiFi y pantalla de 9 pulgadas.', 'specifications' => [['name' => 'DIN', 'value' => 'Un DIN']],
                'images' => [['file' => 'description-1.bin']], 'unavailable_images' => [['source_url' => 'https://ae01.alicdn.com/missing.jpg']]]];
        $this->copy = ['title' => 'Autoradio 9″ 1DIN para Hyundai Kona 2017-2020', 'intro' => 'Redescubre tu música en cada trayecto.',
            'sections' => [['heading' => 'Pantalla', 'items' => ['Pantalla de 9 pulgadas.', 'Formato 1DIN.', '<script>alert(1)</script>']]],
            'closing' => 'Imagina tu próxima escapada con tu canción favorita.', 'notes' => ['Android 13 y 14: verificar.'], 'coverage' => [['spec_index' => 0, 'section_index' => 0, 'item_index' => 1, 'note_index' => null]]];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_generation_overrides_the_default_thirty_second_php_limit(): void
    {
        $previous = (int) ini_get('max_execution_time');
        set_time_limit(30);
        try {
            $this->respond($this->copy);
            app(AutogenesiDescription::class)->generate($this->source, ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020], $this->dir);
            $this->assertGreaterThanOrEqual(600, (int) ini_get('max_execution_time'));
        } finally { set_time_limit($previous); }
    }

    private function respond(array $copy, string $status = 'completed'): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => $status, 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($copy)]]],
        ]])]);
    }

    public function test_generation_returns_source_compatibilities_and_requests_full_original_ranges(): void
    {
        $this->source['description']['text'] .= ' Hyundai Kona 2017-2020.';
        $this->copy['compatibilities'] = [['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020, 'evidence' => 'Hyundai Kona 2017-2020', 'source_file' => null]];
        $this->respond($this->copy);
        $result = app(AutogenesiDescription::class)->generate($this->source, ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2020, 'year_to' => 2020], $this->dir);
        $this->assertSame($this->copy['compatibilities'], $result['compatibilities']);
        Http::assertSent(fn ($request) => in_array('compatibilities', $request['text']['format']['schema']['required'], true)
            && str_contains($request['instructions'], 'intero range del prodotto'));
    }

    public function test_ai_image_selection_is_restricted_to_captured_files_and_invalid_names_do_not_abort_description(): void
    {
        $this->copy['accessories_image'] = 'description-999.bin';
        $this->copy['before_after_image'] = 'description-1.bin';
        $this->respond($this->copy);
        $result = app(AutogenesiDescription::class)->generate($this->source, ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020], $this->dir);
        $this->assertNull($result['accessories_image']);
        $this->assertSame('description-1.bin', $result['before_after_image']);
        $this->assertNotEmpty($result['body_html']);
        $this->assertTrue(collect($result['notes'])->contains(fn ($note) => str_contains($note, 'description-999.bin')));
        Http::assertSent(fn ($request) => $request['text']['format']['schema']['properties']['accessories_image']['enum'] === ['image-1.bin', 'description-1.bin', null]);
    }

    public function test_generation_reads_original_text_specifications_and_images_with_private_notes(): void
    {
        $this->respond($this->copy);
        $result = app(AutogenesiDescription::class)->generate($this->source, ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020], $this->dir);
        $this->assertSame($this->copy['title'], $result['title']);
        $this->assertStringContainsString('<h3>Pantalla</h3>', $result['body_html']);
        $this->assertStringNotContainsString('<script>', $result['body_html']);
        $this->assertStringNotContainsString('Android 13', $result['body_html']);
        $this->assertContains('Android 13 y 14: verificar.', $result['notes']);
        $this->assertContains('Immagine della descrizione non leggibile: https://ae01.alicdn.com/missing.jpg', $result['notes']);
        Http::assertSent(function ($request) {
            $this->assertFalse($request['store']);
            $this->assertSame('json_schema', $request['text']['format']['type']);
            $content = $request['input'][0]['content'];
            $source = json_decode($content[0]['text'], true);
            $this->assertSame('WiFi y pantalla de 9 pulgadas.', $source['description']);
            $this->assertSame('Un DIN', $source['specifications'][0]['value']);
            $this->assertArrayNotHasKey('supplier_price', $source['variants'][0]);
            $this->assertStringNotContainsString('unsupported 360 kit included', json_encode($request->data()));
            $this->assertCount(2, array_filter($content, fn ($v) => $v['type'] === 'input_image'));

            return true;
        });
    }

    public function test_title_and_intro_are_not_substitutes_for_original_description(): void
    {
        unset($this->source['description']);
        $this->expectExceptionMessage('Descrizione originale completa e specifiche obbligatorie');
        try {
            app(AutogenesiDescription::class)->generate($this->source, [], $this->dir);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_incomplete_ai_output_is_rejected(): void
    {
        $this->respond($this->copy, 'incomplete');
        $this->expectExceptionMessage('Generazione incompleta');
        app(AutogenesiDescription::class)->generate($this->source, [], $this->dir);
    }

    public function test_intro_word_limit_is_enforced_without_truncating_specs(): void
    {
        $this->copy['intro'] = implode(' ', array_fill(0, 31, 'palabra'));
        $this->respond($this->copy);
        $this->expectExceptionMessage('limite di parole');
        app(AutogenesiDescription::class)->generate($this->source, [], $this->dir);
    }

    public function test_missing_technical_coverage_preserves_original_spec_in_private_notes(): void
    {
        $this->copy['sections'][0]['items'][] = 'Según la ficha, ver notas.';
        $this->copy['coverage'][0]['spec_index'] = 99;
        $this->respond($this->copy);
        $result = app(AutogenesiDescription::class)->generate($this->source, [], $this->dir);
        $this->assertCount(1, $result['coverage']);
        $this->assertSame(0, $result['coverage'][0]['spec_index']);
        $this->assertStringContainsString('DIN = Un DIN', $result['notes'][$result['coverage'][0]['note_index']]);
    }

    public function test_conflicting_android_versions_cannot_be_promoted_in_the_title(): void
    {
        $this->source['description']['text'] = 'Sistema Android 14.';
        $this->source['description']['specifications'][] = ['name' => 'Sistema operativo', 'value' => 'Android 13'];
        $this->copy['title'] = 'Autoradio Android 14 9″ 1DIN Hyundai Kona 2017-2020';
        $this->respond($this->copy);
        $result = app(AutogenesiDescription::class)->generate($this->source, [], $this->dir);
        $this->assertStringNotContainsString('Android 14', $result['title']);
        $this->assertStringContainsString('Android', $result['title']);
        $this->assertContains('Android 13 y 14: verificar.', $result['notes']);
    }

    public function test_ambiguous_bullet_moves_to_private_notes_and_keeps_technical_coverage(): void
    {
        $this->copy['sections'][0]['items'][1] = 'Según la ficha, formato 1DIN; ver notas.';
        $this->respond($this->copy);
        $result = app(AutogenesiDescription::class)->generate($this->source, [], $this->dir);
        $this->assertStringNotContainsString('ver notas', $result['body_html']);
        $coverage = $result['coverage'][0];
        $this->assertNull($coverage['section_index']);
        $this->assertStringContainsString('formato 1DIN', $result['notes'][$coverage['note_index']]);
    }
}
