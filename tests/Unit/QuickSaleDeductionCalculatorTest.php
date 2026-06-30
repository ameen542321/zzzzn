<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\QuickSaleDeductionCalculator;
use PHPUnit\Framework\TestCase;

class QuickSaleDeductionCalculatorTest extends TestCase
{
    public function test_fractional_custom_sale_uses_calculate_final_deduction(): void
    {
        $product = new Product([
            'product_type' => 'fractional',
            'roll_length' => 20,
        ]);

        $this->assertSame(
            0.25,
            QuickSaleDeductionCalculator::calculate($product, 5, 'unit', true, 5, null)
        );
    }

    public function test_fractional_ready_option_uses_fraction_deduction_value(): void
    {
        $product = new Product([
            'product_type' => 'fractional',
            'roll_length' => 20,
        ]);

        $this->assertSame(
            0.5,
            QuickSaleDeductionCalculator::calculate($product, 1, 'unit', false, null, 0.5)
        );
    }

    public function test_splittable_piece_sale_uses_normalized_piece_quantity(): void
    {
        $product = new Product([
            'product_type' => 'standard',
            'is_splittable' => true,
            'items_per_unit' => 4,
        ]);

        $this->assertSame(
            0.5,
            QuickSaleDeductionCalculator::calculate($product, 2, 'piece')
        );
    }

    public function test_standard_or_full_kit_sale_uses_normalized_non_piece_quantity(): void
    {
        $product = new Product([
            'product_type' => 'standard',
            'is_splittable' => true,
            'items_per_unit' => 4,
        ]);

        $this->assertSame(
            2.0,
            QuickSaleDeductionCalculator::calculate($product, 2, 'unit')
        );
    }
}
