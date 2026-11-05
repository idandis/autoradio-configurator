<?php

namespace Tests\Feature;

use App\Services\AutogenesiCompatibility;
use Tests\TestCase;

class AutogenesiCompatibilityTest extends TestCase
{
    public function test_description_replaces_requested_vehicle_and_preserves_multibrand_year_ranges(): void
    {
        $entries = [
            ['brand' => 'Fiat', 'model' => '500X', 'year_from' => 2014, 'year_to' => 2020, 'evidence' => 'Fiat 500X 2014-2020', 'source_file' => null],
            ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2019, 'year_to' => 2023, 'evidence' => 'Hyundai Kona 2019-2023', 'source_file' => null],
            ['brand' => 'Fiat', 'model' => 'Tipo', 'year_from' => 2020, 'year_to' => 2020, 'evidence' => 'Invented Tipo 2020', 'source_file' => null],
        ];
        $result = app(AutogenesiCompatibility::class)->resolve(['description' => ['text' => 'Compatible con Fiat 500X 2014-2020. Hyundai Kona 2019-2023.']], ['compatibilities' => $entries], ['brand' => 'Fiat', 'model' => 'Tipo', 'year_from' => 2020, 'year_to' => 2020]);
        $this->assertSame(['brand' => 'FIAT | HYUNDAI', 'model' => '1:500X | 2:KONA', 'year_from' => 2014, 'year_to' => 2023], $result['vehicle']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame(2019, $result['entries'][1]['year_from']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_unverified_or_missing_image_evidence_does_not_change_manual_vehicle(): void
    {
        $requested = ['brand' => 'Renault', 'model' => 'Kadjar', 'year_from' => 2020, 'year_to' => 2020];
        $entry = ['brand' => 'Renault', 'model' => 'Kadjar', 'year_from' => 2015, 'year_to' => 2019, 'evidence' => 'Kadjar 2015-2019', 'source_file' => 'missing.bin'];
        $result = app(AutogenesiCompatibility::class)->resolve(['description' => ['text' => 'Radio Android']], ['compatibilities' => [$entry]], $requested);
        $this->assertSame($requested, $result['vehicle']);
        $this->assertSame([], $result['entries']);
        $this->assertNotEmpty($result['warnings']);
    }
}
