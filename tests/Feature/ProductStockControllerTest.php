<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_product_stock_page_with_movements(): void
    {
        [$owner, $store, $product] = $this->createOwnerStoreAndProduct();

        $product->increaseStock(3, 'Initial supply', $owner->id, 'unit');
        $movement = $product->fresh()->stockMovements()->latest('id')->firstOrFail();

        $response = $this
            ->actingAs($owner)
            ->get(route('user.stores.products.stock', [$store, $product]));

        $response->assertOk();
        $response->assertViewIs('user.stores.products.stock.index');
        $response->assertViewHas('store', fn ($viewStore) => $viewStore->is($store));
        $response->assertViewHas('product', fn ($viewProduct) => $viewProduct->is($product));
        $response->assertViewHas('movements', function ($movements) use ($movement) {
            return $movements->contains(fn ($item) => $item->is($movement));
        });
    }

    public function test_stock_routes_return_404_when_product_does_not_belong_to_store(): void
    {
        [$owner, $store] = $this->createOwnerStoreAndProduct();
        $otherStore = $owner->stores()->create([
            'name' => 'Secondary Store',
            'status' => 'active',
            'slug' => 'secondary-store-' . $owner->id,
        ]);

        $foreignProduct = Product::create([
            'store_id' => $otherStore->id,
            'user_id' => $owner->id,
            'category_id' => null,
            'name' => 'Foreign Product',
            'slug' => 'foreign-product',
            'price' => 50,
            'cost_price' => 25,
            'quantity' => 3,
            'status' => 'active',
            'product_type' => 'standard',
            'waste_percentage' => 0,
            'roll_length' => 0,
            'is_splittable' => false,
            'items_per_unit' => 1,
            'piece_price' => 0,
            'min_stock' => 1,
        ]);

        $this->actingAs($owner)
            ->get(route('user.stores.products.stock', [$store, $foreignProduct]))
            ->assertNotFound();
    }

    public function test_owner_can_increase_stock_and_record_movement(): void
    {
        [$owner, $store, $product] = $this->createOwnerStoreAndProduct(quantity: 10);

        $response = $this
            ->actingAs($owner)
            ->from(route('user.stores.products.stock', [$store, $product]))
            ->post(route('user.stores.products.stock.increase', [$store, $product]), [
                'quantity' => 4,
                'unit_type' => 'unit',
                'note' => 'Restock shipment',
            ]);

        $response->assertRedirect(route('user.stores.products.stock', [$store, $product]));
        $response->assertSessionHas('success', 'تمت زيادة المخزون بنجاح');

        $this->assertSame(14.0, (float) $product->fresh()->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'type' => 'increase',
            'quantity' => 4,
            'roll_length_at_movement' => 10,
            'meters' => 14,
            'note' => 'Restock shipment',
        ]);
    }

    public function test_owner_cannot_decrease_stock_beyond_available_quantity(): void
    {
        [$owner, $store, $product] = $this->createOwnerStoreAndProduct(quantity: 5);

        $response = $this
            ->actingAs($owner)
            ->from(route('user.stores.products.stock', [$store, $product]))
            ->post(route('user.stores.products.stock.decrease', [$store, $product]), [
                'quantity' => 6,
                'unit_type' => 'unit',
                'note' => 'Oversell attempt',
            ]);

        $response->assertRedirect(route('user.stores.products.stock', [$store, $product]));
        $response->assertSessionHasErrors(['quantity' => 'الكمية المتوفرة لا تكفي']);

        $this->assertSame(5.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_owner_can_decrease_stock_and_record_movement(): void
    {
        [$owner, $store, $product] = $this->createOwnerStoreAndProduct(quantity: 9);

        $response = $this
            ->actingAs($owner)
            ->from(route('user.stores.products.stock', [$store, $product]))
            ->post(route('user.stores.products.stock.decrease', [$store, $product]), [
                'quantity' => 4,
                'unit_type' => 'unit',
                'note' => 'Manual adjustment',
            ]);

        $response->assertRedirect(route('user.stores.products.stock', [$store, $product]));
        $response->assertSessionHas('success', 'تم خصم الكمية من المخزن بنجاح');

        $this->assertSame(5.0, (float) $product->fresh()->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'type' => 'decrease',
            'quantity' => 4,
            'roll_length_at_movement' => 9,
            'meters' => 5,
            'note' => 'Manual adjustment',
        ]);
    }

    private function createOwnerStoreAndProduct(float $quantity = 10): array
    {
        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'welcome_shown' => true,
            'subscription_end_at' => now()->addDays(30),
        ]);

        $store = $owner->stores()->firstOrFail();
        $store->update(['status' => 'active']);

        $product = Product::create([
            'store_id' => $store->id,
            'user_id' => $owner->id,
            'category_id' => null,
            'name' => 'Stock Test Product',
            'slug' => 'stock-test-product',
            'price' => 100,
            'cost_price' => 60,
            'quantity' => $quantity,
            'status' => 'active',
            'product_type' => 'standard',
            'waste_percentage' => 0,
            'roll_length' => 0,
            'is_splittable' => false,
            'items_per_unit' => 1,
            'piece_price' => 0,
            'min_stock' => 1,
        ]);

        return [$owner, $store, $product];
    }
}
