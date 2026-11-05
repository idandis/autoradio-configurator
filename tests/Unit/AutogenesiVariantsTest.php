<?php

namespace Tests\Unit;

use App\Services\AutogenesiVariants;
use PHPUnit\Framework\TestCase;

class AutogenesiVariantsTest extends TestCase
{
    public function test_names_use_documented_cores_exclude_360_and_sort_prices(): void
    {
        $rows = [
            ['label' => 'PX9 (8GB 256GB)11.5', 'supplier_price' => '357', 'selling_price' => '714', 'sku' => 'a'],
            ['label' => 'PX4 (6GB 128GB)360 White', 'supplier_price' => '222', 'sku' => 'b'],
            ['label' => 'PX1 (2GB 64GB)', 'supplier_price' => '108', 'selling_price' => '219', 'sku' => 'c'],
            ['label' => 'Unknown (2GB 32GB)', 'supplier_price' => '90', 'sku' => 'd'],
        ];
        $specs = [['label' => $rows[0]['label'], 'cores' => 8, 'evidence' => 'description-1.bin PX9 CPU 8-Core'],
            ['label' => $rows[2]['label'], 'cores' => 4, 'evidence' => 'description-1.bin PX1 CPU 4-Core']];
        $out = (new AutogenesiVariants)->normalize($rows, $specs);
        $this->assertSame(['d', 'c', 'a'], array_column($out, 'sku'));
        $this->assertSame(['Unknown (2GB 32GB)', '4Core 2GB 64GB', "8Core 8GB 256GB 11.5''"], array_column($out, 'label'));
        $this->assertSame($rows[0]['label'], $out[2]['source_label']);
        $out[2]['label'] = 'Nome personalizzato';
        $out[2]['label_edited'] = true;
        $this->assertSame('Nome personalizzato', (new AutogenesiVariants)->normalize($out, $specs)[2]['label']);
    }

    public function test_distinct_versions_keep_their_identity(): void
    {
        $rows = array_map(fn ($code) => ['label' => $code.' (6GB 128GB)', 'supplier_price' => '100'], ['PX4', 'PX2 PRO']);
        $specs = array_map(fn ($r) => ['label' => $r['label'], 'cores' => 8, 'evidence' => 'CPU 8-Core'], $rows);
        $this->assertSame(['8Core 6GB 128GB · PX4', '8Core 6GB 128GB · PX2 PRO'], array_column((new AutogenesiVariants)->normalize($rows, $specs), 'label'));
    }
}
