<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreTransferItem extends Model
{
    protected $fillable = [
        'store_transfer_id',
        'sender_product_id',
        'receiver_product_id',
        'requested_quantity',
        'normalized_quantity',
        'unit_type',
        'cost_price',
        'sender_stock_before',
        'sender_stock_after',
        'receiver_stock_before',
        'receiver_stock_after',
    ];

    protected $casts = [
        'requested_quantity' => 'float',
        'normalized_quantity' => 'float',
        'cost_price' => 'float',
        'sender_stock_before' => 'float',
        'sender_stock_after' => 'float',
        'receiver_stock_before' => 'float',
        'receiver_stock_after' => 'float',
    ];

    public function transfer()
    {
        return $this->belongsTo(StoreTransfer::class, 'store_transfer_id');
    }

    public function senderProduct()
    {
        return $this->belongsTo(Product::class, 'sender_product_id');
    }

    public function receiverProduct()
    {
        return $this->belongsTo(Product::class, 'receiver_product_id');
    }
}
