<?php

namespace Tests\Unit;

use App\Support\VehicleTitleParser;
use PHPUnit\Framework\TestCase;

class VehicleTitleParserTest extends TestCase
{
    public function test_it_extracts_generic_model_and_year_range(): void
    {
        $parsed = VehicleTitleParser::parse('Pantalla para Fiat 500 2015-2020', 'Fiat');

        $this->assertSame('500', $parsed['model']);
        $this->assertSame(2015, $parsed['year_from']);
        $this->assertSame(2020, $parsed['year_to']);
    }

    public function test_it_cleans_bmw_series_title_with_marketing_text(): void
    {
        $parsed = VehicleTitleParser::parse('10.25" 12.3" Android 14 Multimedia GPS BMW 5 Series 2011-2017', 'BMW');

        $this->assertSame('serie 5', $parsed['model']);
        $this->assertSame(2011, $parsed['year_from']);
        $this->assertSame(2017, $parsed['year_to']);
    }

    public function test_it_extracts_bmw_chassis_and_trim_codes(): void
    {
        $parsed = VehicleTitleParser::parse('Pantalla de coche 9" BMW E46 M3 318/320/325/330/335 1998-2006', 'BMW');

        $this->assertSame('e46 m3 318 / 320 / 325 / 330 / 335', $parsed['model']);
        $this->assertSame(1998, $parsed['year_from']);
        $this->assertSame(2006, $parsed['year_to']);
    }

    public function test_it_removes_incomplete_parentheses_from_bmw_series_titles(): void
    {
        $parsed = VehicleTitleParser::parse('BMW Serie 1/2/3/4 F20-F36 (NBT EVO) 2012-2020', 'BMW');

        $this->assertSame('serie 1 / 2 / 3 / 4 f20-f36 (nbt evo)', $parsed['model']);
        $this->assertSame(2012, $parsed['year_from']);
        $this->assertSame(2020, $parsed['year_to']);
    }

    public function test_it_normalizes_alfa_romeo_multi_model_titles(): void
    {
        $parsed = VehicleTitleParser::parse('Autoradio OEM Alfa Romeo 159, Brera 2005-2010 y Spider 2006-2010', 'Alfa Romeo');

        $this->assertSame('159 / Brera / Spider', $parsed['model']);
        $this->assertSame(2005, $parsed['year_from']);
        $this->assertSame(2010, $parsed['year_to']);
    }

    public function test_it_removes_year_lists_from_alfa_romeo_models(): void
    {
        $parsed = VehicleTitleParser::parse('Alfa Romeo Giulietta 2012, 2013, 2014', 'Alfa Romeo');

        $this->assertSame('Giulietta', $parsed['model']);
        $this->assertNull($parsed['year_from']);
        $this->assertNull($parsed['year_to']);
    }

    public function test_it_collapses_alfa_romeo_related_models_and_more_suffixes(): void
    {
        $parsed = VehicleTitleParser::parse('Spider/159/Brera 159 Sportwagon - , y más', 'Alfa Romeo');

        $this->assertSame('Spider / 159 / Brera Sportwagon', $parsed['model']);
        $this->assertNull($parsed['year_from']);
        $this->assertNull($parsed['year_to']);
    }

    public function test_it_removes_din_noise_and_empty_parentheses(): void
    {
        $parsed = VehicleTitleParser::parse('1 DIN Toyota IQ () 2008-2014', 'Toyota');

        $this->assertSame('IQ', $parsed['model']);
        $this->assertSame(2008, $parsed['year_from']);
        $this->assertSame(2014, $parsed['year_to']);
    }
}
