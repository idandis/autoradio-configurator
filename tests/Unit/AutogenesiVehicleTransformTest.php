<?php

namespace Tests\Unit;

use App\Services\AutogenesiCompatibility;
use App\Services\AutogenesiVehicleTransform;
use Tests\TestCase;

class AutogenesiVehicleTransformTest extends TestCase
{
    public function test_manual_uppercase_model_names_are_preserved(): void
    {
        $t = app(AutogenesiVehicleTransform::class);
        $this->assertSame('XJ | XJL | ABCDEF', $t->fields([
            ['brand' => 'JAGUAR', 'model' => 'XJ', 'origin' => 'manual'],
            ['brand' => 'JAGUAR', 'model' => 'XJL', 'origin' => 'manual'],
            ['brand' => 'JAGUAR', 'model' => 'ABCDEF', 'origin' => 'manual'],
        ])['model']);
    }

    public function test_single_letter_generation_codes_remain_uppercase(): void
    {
        $t = app(AutogenesiVehicleTransform::class);
        $this->assertSame('Astra J', $t->model('Astra J'));
        $this->assertSame('Astra J', $t->model('ASTRA J'));
        $this->assertSame('Astra J', $t->model('Astra j'));
    }

    public function test_single_brand_has_no_indexes_and_aliases_are_not_duplicated(): void
    {
        $t = app(AutogenesiVehicleTransform::class);
        $this->assertSame(['brand' => 'VOLVO', 'model' => 'S60 | V70 | XC70'], $t->fields([
            ['brand' => 'Volvo', 'model' => 'S60'], ['brand' => 'VOLVO', 'model' => 'V70'], ['brand' => 'Volvo', 'model' => 'XC70'],
        ]));
        $this->assertSame(['brand' => 'FORD', 'model' => 'S-MAX | Galaxy', 'year_from' => 2006, 'year_to' => 2015], $t->groups([
            ['brand' => 'Ford', 'model' => 'S MAX / S-MAX', 'year_from' => 2006, 'year_to' => 2015],
            ['brand' => 'FORD', 'model' => 'GALAXY', 'year_from' => 2006, 'year_to' => 2015],
        ])[0]);
    }

    public function test_multiple_brands_keep_indexes_and_distinct_year_ranges(): void
    {
        $t = app(AutogenesiVehicleTransform::class);
        $this->assertSame(['brand' => 'FIAT | CITROEN | PEUGEOT', 'model' => '1:Ducato | 2:Jumper | 3:Boxer'], $t->fields([
            ['brand' => 'Fiat', 'model' => 'Ducato'], ['brand' => 'Citroen', 'model' => 'Jumper'], ['brand' => 'Peugeot', 'model' => 'Boxer'],
        ]));
        $this->assertCount(2, $t->groups([
            ['brand' => 'Ford', 'model' => 'Galaxy', 'year_from' => 2006, 'year_to' => 2010],
            ['brand' => 'Ford', 'model' => 'Galaxy', 'year_from' => 2012, 'year_to' => 2015],
        ]));
    }

    public function test_simplified_generation_name_requires_source_proof_of_shared_fitment(): void
    {
        $quote = 'BMW Serie 1 E81/E82/E87/E88 2004-2013.';
        $proof = 'Mismo tablero y mismo cableado para todas estas versiones.';
        $data = ['source_title' => 'Radio BMW', 'description' => ['text' => $quote.' '.$proof, 'specifications' => []], 'images' => []];
        $entry = ['brand' => 'BMW', 'model' => 'Serie 1 E81/E82/E87/E88', 'year_from' => 2004, 'year_to' => 2013,
            'evidence' => $quote, 'source_file' => null, 'display_model' => 'Serie 1 E8x', 'shared_fitment_evidence' => $proof];
        $service = app(AutogenesiCompatibility::class);
        $r = $service->resolve($data, ['compatibilities' => [$entry]], []);
        $this->assertSame('Serie 1 E8X', $r['vehicle']['model']);
        $this->assertSame($entry['model'], $r['entries'][0]['source_model']);
        $entry['shared_fitment_evidence'] = 'An unsupported claim about identical dashboards.';
        $r = $service->resolve($data, ['compatibilities' => [$entry]], []);
        $this->assertSame('Serie 1 E81/E82/E87/E88', $r['vehicle']['model']);
    }

    public function test_unverified_vehicle_still_gets_formatting_without_claiming_compatibility(): void
    {
        $r = app(AutogenesiCompatibility::class)->resolve([], [], ['brand' => 'ford', 'model' => 'GALAXY', 'year_from' => 2006, 'year_to' => 2015]);
        $this->assertSame('FORD', $r['vehicle']['brand']);
        $this->assertSame('Galaxy', $r['vehicle']['model']);
        $this->assertSame([], $r['entries']);
        $this->assertNotEmpty($r['warnings']);
    }
}
