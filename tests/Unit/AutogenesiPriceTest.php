<?php

namespace Tests\Unit;

use App\Services\AutogenesiPrice;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AutogenesiPriceTest extends TestCase
{
    public function test_customs_estimate_replaces_document_igic_with_requested_vat(): void
    {
        $p = (new AutogenesiPrice)->quote('210.39');
        $this->assertSame('228.71', $p['customs_base_estimate']);
        $this->assertSame('32.02', $p['duty_estimate']);
        $this->assertSame('54.75', $p['import_vat_estimate']);
        $this->assertSame('32.66', $p['correos_estimate']);
        $this->assertSame('119.43', $p['customs_estimate']);
        $this->assertSame('479.82', $p['before_rounding']);
        $this->assertSame('480', $p['selling_price']);
    }

    public function test_tier_boundaries_and_rounding_up_to_whole_euros(): void
    {
        foreach ([['149.99', '110.00', '260'], ['150.00', '150.00', '397'], ['249.99', '150.00', '535'],
            ['250.00', '170.00', '555'], ['349.99', '170.00', '693'], ['350.00', '180.00', '703'],
            ['449.99', '180.00', '841'], ['450.00', '200.00', '861'], ['500.00', '200.00', '930']] as [$cost, $markup, $selling]) {
            $p = (new AutogenesiPrice)->quote($cost);
            $this->assertSame($markup, $p['markup'], $cost);
            $this->assertSame($selling, $p['selling_price'], $cost);
        }
    }

    public function test_below_150_uses_only_the_requested_markup_and_does_not_add_an_extra_euro(): void
    {
        $p = (new AutogenesiPrice)->quote('100');
        $this->assertSame('0.00', $p['customs_estimate']);
        $this->assertSame('210.00', $p['before_rounding']);
        $this->assertSame('210', $p['selling_price']);
    }

    public function test_imported_variant_examples(): void
    {
        foreach (['343.69' => '684', '382.39' => '748', '448.39' => '839'] as $cost => $selling) {
            $this->assertSame($selling, (new AutogenesiPrice)->quote($cost)['selling_price']);
        }
    }

    public function test_negative_cost_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AutogenesiPrice)->quote('-1.00');
    }
}
