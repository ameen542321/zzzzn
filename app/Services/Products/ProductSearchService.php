<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * خدمة موحدة للبحث عن منتجات المتجر في الشاشات المختلفة.
 *
 * تجمع منطق البحث العربي، وترتيب النتائج حسب الصلة وحالة المخزون، وتجهيز
 * بيانات البيع السريع حتى لا تتكرر نفس شروط LIKE/Raw في الكنترولرات.
 */
class ProductSearchService
{
    /** @var array<int,string> */
    private array $defaultSearchColumns = ['name', 'description', 'barcode'];

    /**
     * يطبق بحثاً موحداً على استعلام منتجات قائم، مع دعم تطبيع عربي بسيط.
     *
     * @param  mixed  $query
     * @param  array<int,string>|null  $columns
     * @return mixed
     */
    public function applySearch($query, ?string $term, ?array $columns = null)
    {
        $searchTerm = trim((string) $term);

        if ($searchTerm === '') {
            return $query;
        }

        $columns = $columns ?: $this->defaultSearchColumns;
        $normalizedSearchTerm = $this->normalizeArabicSearchTerm($searchTerm);

        return $query->where(function ($q) use ($columns, $searchTerm, $normalizedSearchTerm): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'LIKE', '%' . $searchTerm . '%');

                if (in_array($column, ['name', 'description'], true)) {
                    $q->orWhereRaw($this->normalizedArabicSql($column) . ' LIKE ?', ['%' . $normalizedSearchTerm . '%']);
                }
            }
        });
    }

    /**
     * بحث صفحة منتجات المالك: البحث فقط، وتبقى فلاتر/ترتيب الصفحة في الكنترولر.
     *
     * @param  mixed  $query
     * @return mixed
     */
    public function applyOwnerCatalogSearch($query, ?string $term)
    {
        return $this->applySearch($query, $term, ['name', 'description', 'barcode']);
    }

    /**
     * بحث صفحات منتجات المحاسب/نقطة البيع: البحث فقط بدون تغيير طريقة العرض.
     *
     * @param  mixed  $query
     * @return mixed
     */
    public function applyAccountantCatalogSearch($query, ?string $term)
    {
        return $this->applySearch($query, $term, ['name', 'description', 'barcode']);
    }

    /**
     * نتائج مجهزة لشاشة البيع السريع.
     *
     * @return Collection<int,Product>
     */
    public function quickSaleResults(int $storeId, ?string $term): Collection
    {
        $searchTerm = trim((string) $term);

        $query = Product::with(['fractions' => function ($q): void {
                $q->select('id', 'product_id', 'option_label', 'price', 'deduction_value');
            }])
            ->where('store_id', $storeId)
            ->where('status', 'active');

        $this->applySearch($query, $searchTerm !== '' ? $searchTerm : null, ['name', 'description', 'barcode']);
        $this->applyQuickSaleOrdering($query, $searchTerm !== '' ? $searchTerm : null);

        $limit = $searchTerm !== '' ? 30 : 4;

        return $query
            ->limit($limit)
            ->get([
                'id', 'name', 'price', 'piece_price', 'description', 'barcode', 'updated_at',
                'product_type', 'quantity', 'min_stock',
                'waste_percentage', 'roll_length',
                'is_splittable', 'items_per_unit', 'quick_sale_default_unit',
            ])
            ->map(fn (Product $product): Product => $this->formatQuickSaleProduct($product));
    }

    /**
     * نتائج مختصرة لواجهات الاستهلاك الداخلي وأي منتقي منتجات مشابه.
     *
     * @return Collection<int,Product>
     */
    public function pickerResults(int $storeId, ?string $term, int $limit = 30): Collection
    {
        $query = Product::where('store_id', $storeId)
            ->where('status', 'active');

        $this->applySearch($query, $term, ['name', 'barcode', 'description']);
        $query->orderBy('name', 'asc');

        return $query
            ->limit($limit)
            ->get([
                'id',
                'name',
                'cost_price',
                'barcode',
                'description',
                'product_type',
                'is_splittable',
                'items_per_unit',
                'roll_length',
                'piece_price',
            ]);
    }

    /**
     * ترتيب خاص بالبيع السريع فقط: الصلة أولاً عند البحث، والأكثر بيعاً عند العرض الافتراضي،
     * ثم المنتجات المتوفرة قبل المنتهية/منخفضة المخزون.
     *
     * @param  mixed  $query
     * @return mixed
     */
    private function applyQuickSaleOrdering($query, ?string $term = null)
    {
        $searchTerm = trim((string) $term);

        if ($searchTerm !== '') {
            $normalizedSearchTerm = $this->normalizeArabicSearchTerm($searchTerm);
            $normalizedNameSql = $this->normalizedArabicSql('name');
            $normalizedDescriptionSql = $this->normalizedArabicSql('description');

            $query->orderByRaw("CASE
                WHEN LOWER(name) = LOWER(?) THEN 0
                WHEN LOWER(name) LIKE LOWER(?) THEN 1
                WHEN LOWER(name) LIKE LOWER(?) THEN 2
                WHEN $normalizedNameSql = ? THEN 3
                WHEN $normalizedNameSql LIKE ? THEN 4
                WHEN $normalizedNameSql LIKE ? THEN 5
                WHEN barcode LIKE ? THEN 6
                WHEN $normalizedDescriptionSql LIKE ? THEN 7
                WHEN LOWER(description) LIKE LOWER(?) THEN 8
                ELSE 9
            END", [
                $searchTerm,
                $searchTerm . '%',
                '%' . $searchTerm . '%',
                $normalizedSearchTerm,
                $normalizedSearchTerm . '%',
                '%' . $normalizedSearchTerm . '%',
                '%' . $searchTerm . '%',
                '%' . $normalizedSearchTerm . '%',
                '%' . $searchTerm . '%',
            ]);
        } else {
            $query->withSum('saleItems as total_sold', 'quantity')
                ->orderByDesc('total_sold');
        }

        return $query
            ->orderByRaw("CASE WHEN product_type = 'fractional' AND roll_length > 0 THEN ((quantity / roll_length) <= 0) ELSE (quantity <= 0) END ASC")
            ->orderByRaw("CASE WHEN product_type = 'fractional' AND roll_length > 0 THEN ((quantity / roll_length) <= min_stock) ELSE (quantity <= min_stock) END ASC")
            ->orderBy('name', 'asc');
    }

    private function formatQuickSaleProduct(Product $product): Product
    {
        $product->price = (float) $product->price;
        $product->piece_price = (float) ($product->piece_price ?? 0);
        $product->price_label = number_format($product->price, 0) . ' ر.س';
        $product->piece_price_label = number_format($product->piece_price, 0) . ' ر.س';
        $product->price_updated_at = optional($product->updated_at)->toDateTimeString();

        $product->is_fractional = ($product->product_type === 'fractional');
        $product->is_set = ((int) $product->is_splittable === 1 && (float) $product->items_per_unit > 0);

        $displayQuantity = (float) $product->quantity;

        if ($product->is_fractional) {
            if ((float) $product->roll_length > 0) {
                $displayQuantity = (float) $product->quantity / (float) $product->roll_length;
                $product->display_quantity = number_format($displayQuantity, 2);
                $product->display_unit = 'رول';
                $product->display_min_stock = number_format((float) $product->min_stock, 2);
                $product->meter_price = number_format((float) $product->price / (float) $product->roll_length, 2);
            } else {
                $product->display_quantity = number_format($displayQuantity, 2);
                $product->display_unit = 'متر';
                $product->display_min_stock = number_format((float) $product->min_stock, 2);
            }
        } elseif ($product->is_set) {
            $product->display_quantity = number_format($displayQuantity, 2);
            $product->display_unit = 'طقم';
            $product->display_min_stock = number_format((float) $product->min_stock, 2);
        } else {
            $product->display_quantity = number_format($displayQuantity, 2);
            $product->display_unit = 'قطعة';
            $product->display_min_stock = number_format((float) $product->min_stock, 2);
        }

        $product->is_out_of_stock = $displayQuantity <= 0;
        $product->is_low_stock = !$product->is_out_of_stock && $displayQuantity <= (float) $product->min_stock;

        return $product;
    }

    private function normalizeArabicSearchTerm(string $value): string
    {
        return strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ئ' => 'ي',
            'ؤ' => 'و',
            'ة' => 'ه',
        ]);
    }

    private function normalizedArabicSql(string $column): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($column, 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا'), 'ى', 'ي'), 'ئ', 'ي'), 'ؤ', 'و'), 'ة', 'ه'))";
    }
}
