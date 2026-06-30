<?php

namespace App\Models;

use App\Models\ProductFraction;
use App\Services\NotificationService;
use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use SoftDeletes, BelongsToStore;

    public const INVENTORY_AUDIT_CONFIRMED_TYPE = 'stock_audit_confirmed';

    protected $fillable = [
        'store_id',
        'user_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'cost_price',
        'product_type',
        'waste_percentage',
        'quantity',
        'barcode',
        'status',
        'image',
        'piece_price',
        'min_stock',
        'roll_length',
        'is_splittable',
        'items_per_unit',
        'quick_sale_default_unit',
    ];

    protected static function boot()
    {
        parent::boot();

        // توليد الرابط المختصر مع دعم الكلمات العربية.
        static::creating(function ($product) {
            $product->slug = $product->slug ?: Str::slug($product->name, '-', null);
        });

        static::updating(function ($product) {
            /*
             * يجب الحفاظ على الـ slug الحالي عند تعديل السعر أو المخزون أو أي حقل
             * لا يغيّر اسم المنتج؛ لأن بعض المنتجات تستخدم slug مرتبطاً بالمتجر
             * مثل product-name-s1، واستبداله بـ product-name قد يصطدم بمنتج قديم
             * في متجر آخر ويُظهر للمستخدم رسالة تكرار اسم غير صحيحة.
             *
             * إذا تغيّر الاسم فعلياً ولم يرسل المستدعي slug مخصصاً، نعيد توليده
             * تلقائياً للمحافظة على السلوك المعتاد للنموذج.
             */
            if ($product->isDirty('name') && ! $product->isDirty('slug')) {
                $product->slug = Str::slug($product->name, '-', null);
            }
        });

        // إشعار انخفاض المخزون عند عبور الحد الأدنى فقط لتقليل تكرار الإشعارات.
        static::updated(function ($product) {
            if ($product->wasChanged('quantity')) {
                $product->checkAndTriggerLowStockNotification((float) $product->getOriginal('quantity'));
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes (الاستعلامات المخصصة)
    |--------------------------------------------------------------------------
    */

    /**
     * جلب المنتجات التي وصلت أو نزلت عن حد المخزون الأدنى مع تقريب الكسور.
     */
    public function scopeLowStock($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($fractional) {
                $fractional->where('product_type', 'fractional')
                    ->where('roll_length', '>', 0)
                    ->whereRaw('ROUND(quantity / roll_length, 4) <= ROUND(min_stock, 4)');
            })->orWhere(function ($normal) {
                $normal->where(function ($inner) {
                    $inner->where('product_type', '!=', 'fractional')
                        ->orWhere('roll_length', '<=', 0)
                        ->orWhereNull('roll_length');
                })->whereRaw('ROUND(quantity, 4) <= ROUND(min_stock, 4)');
            });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | حسابات الكميات والوحدات
    |--------------------------------------------------------------------------
    */

    /**
     * حساب الخصم النهائي شاملاً نسبة الهالك.
     */
    public function calculateFinalDeduction($value, $unitType = 'default')
    {
        $deduction = 0;

        if ($this->product_type === 'fractional') {
            if (in_array($unitType, ['meters', 'meter', 'custom'], true)) {
                $deduction = (float) $value;
            } elseif ((float) $this->roll_length > 0) {
                $deduction = (float) $value * (float) $this->roll_length;
            } else {
                $deduction = (float) $value;
            }
        } elseif ($this->is_splittable) {
            $deduction = $unitType === 'piece'
                ? (float) ($value / ($this->items_per_unit ?: 1))
                : (float) $value;
        } else {
            $deduction = (float) $value;
        }

        if ((float) $this->waste_percentage > 0) {
            $deduction += $deduction * ((float) $this->waste_percentage / 100);
        }

        return $deduction;
    }

    /**
     * تحويل الكمية المدخلة من وحدة العرض/الإدخال إلى وحدة المخزون الأساسية.
     *
     * ملاحظة: لا نستخدم abs هنا حتى لا نخفي الكميات السالبة المرسلة بالخطأ.
     */
    public function normalizeQuantityByUnit($quantity, $unitType = 'unit'): float
    {
        $q = (float) $quantity;
        if ($q <= 0) {
            return 0.0;
        }

        if (in_array($unitType, ['default', 'normalized'], true)) {
            return $q;
        }

        if ($this->product_type === 'fractional') {
            if (in_array($unitType, ['unit', 'roll'], true) && (float) $this->roll_length > 0) {
                return $q * (float) $this->roll_length;
            }

            if (in_array($unitType, ['meter', 'meters', 'custom'], true)) {
                return $q;
            }
        }

        if ($this->is_splittable && (int) $this->items_per_unit > 1) {
            if ($unitType === 'piece') {
                return $q / (float) $this->items_per_unit;
            }

            if (in_array($unitType, ['kit', 'unit', 'default'], true)) {
                return $q;
            }
        }

        return $q;
    }

    /**
     * تحويل الأمتار إلى نسبة خصم من الرول.
     */
    public function convertMetersToDeduction($meters)
    {
        if ((float) $this->roll_length <= 0) {
            return 0;
        }

        return (float) ($meters / $this->roll_length);
    }

    /*
    |--------------------------------------------------------------------------
    | الإشعارات
    |--------------------------------------------------------------------------
    */

    /**
     * فحص مخزون المنتج وإرسال إشعار إذا عبر من فوق الحد الأدنى إلى الحد أو دونه.
     */
    public function checkAndTriggerLowStockNotification(?float $previousQuantity = null): void
    {
        $limit = (float) ($this->min_stock ?? 1);
        $currentStockInUnits = $this->stockQuantityInDisplayUnits((float) $this->quantity);

        if ($previousQuantity !== null) {
            $previousStockInUnits = $this->stockQuantityInDisplayUnits($previousQuantity);
            if ($previousStockInUnits <= $limit || $currentStockInUnits > $limit) {
                return;
            }
        } elseif ($currentStockInUnits > $limit) {
            return;
        }

        NotificationService::sendTemplate('low_stock', [
            'sender_type' => 'system',
            'target_type' => 'store',
            'target_ids' => [$this->store_id],
            'product_name' => $this->name,
            'quantity' => round($currentStockInUnits, 2),
        ]);
    }

    private function stockQuantityInDisplayUnits(float $quantity): float
    {
        if ($this->product_type === 'fractional' && (float) $this->roll_length > 0) {
            return $quantity / (float) $this->roll_length;
        }

        return $quantity;
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات
    |--------------------------------------------------------------------------
    */

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function fractions()
    {
        return $this->hasMany(ProductFraction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class);
    }

    public static function inventoryAuditCycleStart(?Store $store = null)
    {
        $anchor = ($store?->created_at ?? now())->copy()->startOfDay();
        $monthsFromStoreCreation = (int) $anchor->diffInMonths(now());

        return $anchor->copy()->addMonths(intdiv($monthsFromStoreCreation, 6) * 6);
    }

    public function inventoryAuditStatus(?Store $store = null): array
    {
        $missing = [];

        if ((float) $this->price <= 0) {
            $missing[] = 'سعر البيع';
        }

        if (is_null($this->cost_price) || (float) $this->cost_price <= 0) {
            $missing[] = 'التكلفة';
        }

        $hasEnteredQuantity = (float) $this->quantity > 0 || $this->stockMovements()->exists();
        if (! $hasEnteredQuantity) {
            $missing[] = 'الكمية أو سجل حركة مخزون';
        }

        if ($missing !== []) {
            return [
                'color' => 'red',
                'label' => 'بيانات ناقصة',
                'message' => 'ينقص: ' . implode('، ', $missing),
                'can_confirm' => false,
                'cycle_start' => self::inventoryAuditCycleStart($store ?? $this->store),
                'confirmed_at' => null,
            ];
        }

        $cycleStart = self::inventoryAuditCycleStart($store ?? $this->store);
        $latestConfirmation = $this->inventoryLogs()
            ->where('type', self::INVENTORY_AUDIT_CONFIRMED_TYPE)
            ->where('created_at', '>=', $cycleStart)
            ->latest()
            ->first();

        if ($latestConfirmation) {
            return [
                'color' => 'green',
                'label' => 'مكتمل ومجرود',
                'message' => 'تم تأكيد جرد المنتج في دورة الجرد الحالية.',
                'can_confirm' => false,
                'cycle_start' => $cycleStart,
                'confirmed_at' => $latestConfirmation->created_at,
            ];
        }

        return [
            'color' => 'yellow',
            'label' => 'مكتمل دون تأكيد جرد',
            'message' => 'البيانات مكتملة، لكن المنتج لم يتم تأكيد جرده في دورة الجرد الحالية.',
            'can_confirm' => true,
            'cycle_start' => $cycleStart,
            'confirmed_at' => null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | خصائص محسوبة
    |--------------------------------------------------------------------------
    */

    public function getPiecePriceAttribute($value)
    {
        if ((float) $value > 0) {
            return $value;
        }

        if ($this->is_splittable && (int) $this->items_per_unit > 0) {
            return (float) $this->price / (int) $this->items_per_unit;
        }

        return $this->price;
    }

    /*
    |--------------------------------------------------------------------------
    | دوال إدارة المخزون
    |--------------------------------------------------------------------------
    */

    /**
     * @deprecated استخدم decreaseStock بعد قفل المنتج داخل transaction.
     */
    public function deductStock($amount): void
    {
        $this->decreaseStock($amount, null, null, 'normalized');
    }

    /**
     * يجب استدعاؤها على منتج مقفول داخل transaction في العمليات الحساسة.
     */
    public function increaseStock($quantity, ?string $note = null, ?int $userId = null, $unitType = 'default'): void
    {
        $actualAmount = $this->normalizeQuantityByUnit($quantity, $unitType);
        if ($actualAmount <= 0) {
            return;
        }

        $before = (float) $this->getRawOriginal('quantity');
        $this->increment('quantity', $actualAmount);
        $after = $before + $actualAmount;
        $this->forceFill(['quantity' => $after]);

        StockMovement::recordForProduct($this, 'increase', $actualAmount, $before, $after, $userId, $note);
    }

    /**
     * يجب استدعاؤها على منتج مقفول داخل transaction في العمليات الحساسة.
     */
    public function decreaseStock($quantity, ?string $note = null, ?int $userId = null, $unitType = 'default'): void
    {
        $actualAmount = $this->normalizeQuantityByUnit($quantity, $unitType);
        if ($actualAmount <= 0) {
            return;
        }

        $before = (float) $this->getRawOriginal('quantity');
        if (round($before, 4) < round($actualAmount, 4)) {
            throw ValidationException::withMessages(['quantity' => 'الكمية المتوفرة لا تكفي لإتمام العملية.']);
        }

        $this->decrement('quantity', $actualAmount);
        $after = $before - $actualAmount;
        $this->forceFill(['quantity' => $after]);

        StockMovement::recordForProduct($this, 'decrease', $actualAmount, $before, $after, $userId, $note);
    }
}
