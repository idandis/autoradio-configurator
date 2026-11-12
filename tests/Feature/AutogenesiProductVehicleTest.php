<?php

namespace Tests\Feature;

use App\Services\AutogenesiProductVehicle;
use Tests\TestCase;

class AutogenesiProductVehicleTest extends TestCase
{
    public function test_uses_vehicle_and_years_from_product_title(): void
    {
        $vehicle = app(AutogenesiProductVehicle::class)->infer([
            'ae_item_base_info_dto' => [
                'subject' => 'Car Radio Android 16 for Opel Astra J Vauxhall Holden Cascada 2009-2018 Multimedia Screen GPS Carplay',
                'detail' => str_repeat('<p>Accessories for other years 2010-2015.</p>', 1000),
            ],
        ]);

        $this->assertSame(['brand' => 'Opel', 'model' => 'Astra J', 'year_from' => 2009, 'year_to' => 2018], $vehicle);
    }
}
