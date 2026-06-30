<?php

namespace Tests\Feature;

use App\Models\Accountant;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalUseControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_internal_use_piece_flow_preserves_unit_type_and_normalized_stock_deduction(): void
    {
        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'welcome_shown' => true,
            'subscription_end_at' => now()->addDays(30),
        ]);

        $store = Store::factory()->create([
            'user_id' => $owner->id,
            'status' => 'active',
        ]);

        $accountant = Accountant::create([
            'user_id' => $owner->id,
            'store_id' => $store->id,
            'employee_id' => null,
            'name' => 'Internal Use Accountant',
            'email' => 'internal-use@example.com',
            'phone' => '0500000001',
            'password' => 'password',
            'status' => 'active',
        ]);

        $product = Product::create([
            'store_id' => $store->id,
            'user_id' => $owner->id,
            'category_id' => null,
            'name' => 'Splittable Kit',
            'slug' => 'splittable-kit',
            'price' => 100,
            'cost_price' => 70,
            'quantity' => 10,
            'status' => 'active',
            'product_type' => 'standard',
            'waste_percentage' => 0,
            'roll_length' => 0,
            'is_splittable' => true,
            'items_per_unit' => 4,
            'piece_price' => 30,
            'min_stock' => 1,
        ]);

        $response = $this
            ->actingAs($accountant, 'accountant')
            ->from(route('accountant.internal-use.create'))
            ->post(route('accountant.internal-use.store'), [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_type' => 'piece',
                'reason' => 'Internal consumption',
                'internal_notes' => 'Two pieces used',
            ]);

        $response->assertRedirect(route('accountant.internal-use.create'));
        $response->assertSessionHas('success');

        $this->assertSame(9.5, (float) $product->fresh()->quantity);

        $sale = Sale::query()->firstOrFail();
        $saleItem = SaleItem::query()->firstOrFail();

        $this->assertSame('internal_use', $sale->sale_type);
        $this->assertSame(60.0, (float) $sale->total);
        $this->assertSame(2.0, (float) $saleItem->quantity);
        $this->assertSame('piece', $saleItem->unit_type);

        $this->assertDatabaseHas('stock_movements', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'type' => 'decrease',
            'quantity' => 0.5,
            'roll_length_at_movement' => 10,
            'meters' => 9.5,
        ]);
    }
}
