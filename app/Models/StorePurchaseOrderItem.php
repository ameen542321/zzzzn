<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePurchaseOrderItem extends Model
{
    protected $fillable = [
        'store_purchase_order_id', 'product_id', 'matched_product_id', 'custom_product_name',
        'quantity_requested', 'quantity_received', 'unit_type', 'cost_price_at_order',
        'cost_price_at_receipt', 'price_variance', 'price_variance_percent',
        'update_product_cost', 'receipt_notes',
    ];

    protected $casts = [
        'quantity_requested' => 'float',
        'quantity_received' => 'float',
        'cost_price_at_order' => 'float',
        'cost_price_at_receipt' => 'float',
        'price_variance' => 'float',
        'price_variance_percent' => 'float',
        'update_product_cost' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(StorePurchaseOrder::class, 'store_purchase_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function matchedProduct()
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }

    public function productName(): string
    {
        return $this->product?->name ?? $this->matchedProduct?->name ?? $this->custom_product_name ?? 'منتج';
    }
}
