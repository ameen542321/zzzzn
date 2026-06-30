<?php

namespace Tests\Unit;

use App\Models\StockMovement;
use PHPUnit\Framework\TestCase;

class StockMovementAccessorsTest extends TestCase
{
    public function test_previous_and_current_balance_accessors_read_existing_columns(): void
    {
        $movement = new StockMovement([
            'quantity' => '2.50',
            'roll_length_at_movement' => '15.75',
            'meters' => '18.25',
        ]);

        $this->assertSame(2.5, $movement->quantity);
        $this->assertSame(15.75, $movement->previous_balance);
        $this->assertSame(18.25, $movement->current_balance);
    }

    public function test_balance_accessors_default_to_zero_when_columns_are_missing(): void
    {
        $movement = new StockMovement();

        $this->assertSame(0.0, $movement->previous_balance);
        $this->assertSame(0.0, $movement->current_balance);
    }
}
