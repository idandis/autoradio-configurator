<?php

namespace Tests\Unit;

use App\Services\VehicleImageResolver;
use Tests\TestCase;

class VehicleImageResolverTest extends TestCase
{
    public function test_it_reuses_one_image_for_facelifts_in_the_same_generation(): void
    {
        $resolver = app(VehicleImageResolver::class);
        $files = ['mercedes-clase-c-2011-2014.png'];

        $this->assertSame(
            'mercedes-clase-c-2011-2014.png',
            $resolver->resolveFilename('MERCEDES', 'Clase C W204', 2008, $files),
        );
        $this->assertSame(
            'mercedes-clase-c-2011-2014.png',
            $resolver->resolveFilename('MERCEDES', 'Clase C W204', 2013, $files),
        );
    }

    public function test_it_keeps_radically_different_generations_separate(): void
    {
        $resolver = app(VehicleImageResolver::class);
        $files = [
            'mercedes-clase-c-2000-2004.webp',
            'mercedes-clase-c-2011-2014.png',
        ];

        $this->assertSame(
            'mercedes-clase-c-2000-2004.webp',
            $resolver->resolveFilename('MERCEDES', 'Clase C W203', 2005, $files),
        );
        $this->assertSame(
            'mercedes-clase-c-2011-2014.png',
            $resolver->resolveFilename('MERCEDES', 'Clase C W204', 2011, $files),
        );
    }

    public function test_it_reuses_the_same_r230_image_across_product_year_ranges(): void
    {
        $resolver = app(VehicleImageResolver::class);
        $files = ['mercedes-sl-2008-2012.webp'];

        $this->assertSame(
            $resolver->resolveFilename('MERCEDES', 'SL R230', 2003, $files),
            $resolver->resolveFilename('MERCEDES', 'SL R230', 2011, $files),
        );
    }

    public function test_it_does_not_use_a_different_generation_as_fallback(): void
    {
        $resolver = app(VehicleImageResolver::class);

        $this->assertNull($resolver->resolveFilename(
            'MERCEDES',
            'Clase B W245',
            2013,
            ['mercedes-clase-b-2013-2015.png'],
        ));
    }

    public function test_it_expands_indexed_multibrand_models(): void
    {
        $resolver = app(VehicleImageResolver::class);

        $this->assertSame([
            ['brand' => 'PEUGEOT', 'model' => '208'],
            ['brand' => 'CITROEN', 'model' => 'C4'],
        ], $resolver->vehicleEntries('PEUGEOT | CITROEN', '1:208 | 2:C4'));
    }

    public function test_it_rejects_incomplete_indexed_multibrand_data(): void
    {
        $resolver = app(VehicleImageResolver::class);

        $this->assertSame([], $resolver->vehicleEntries(
            'CITROEN',
            '1:208 | 1:308 | 2:C4 | 2:C5',
        ));
    }
}
