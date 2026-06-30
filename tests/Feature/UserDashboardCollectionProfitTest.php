<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDashboardCollectionProfitTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_profit_uses_collected_amount_as_sale_basis(): void
    {
        $owner = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'subscription_end_at' => now()->addDays(30),
        ]);

        $store = Store::factory()->create([
            'user_id' => $owner->id,
            'status' => 'active',
        ]);

        Sale::create([
            'store_id' => $store->id,
            'employee_id' => null,
            'accountant_id' => null,
            'sale_type' => 'cash',
            'products_total' => 20,
            'tax_rate' => 0,
            'labor_total' => 0,
            'final_total' => 20,
            'paid_amount' => 40,
            'cash_amount' => 40,
            'card_amount' => 0,
            'remaining_amount' => 0,
            'has_partial_credit' => false,
            'profit' => 8,
            'total' => 20,
            'has_invoice' => false,
            'description' => 'Collected amount is sale basis',
            'created_at' => now()->startOfDay()->addHours(10),
            'updated_at' => now()->startOfDay()->addHours(10),
        ]);

        $response = $this
            ->actingAs($owner, 'web')
            ->get(route('user.dashboard'));

        $response->assertOk();
        $response->assertSee('28', false);
    }
}
