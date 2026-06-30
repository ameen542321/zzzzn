<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePurchaseOrder extends Model
{
    protected $fillable = [
        'store_id', 'user_id', 'supplier_name', 'status', 'notes',
        'sent_at', 'received_at', 'approved_at', 'cancelled_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(StorePurchaseOrderItem::class);
    }

    public function scopeForOwner($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
