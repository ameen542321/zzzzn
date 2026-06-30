<?php

namespace App\Services\Accounting;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesCostService
{
    /**
     * يحسب تكلفة المنتجات المباعة خلال فترة محددة مع الحفاظ على fallback موحد للعمليات القديمة.
     */
    public function soldProductsCostForPeriod(int $storeId, $periodStart, $periodEnd, array $includedSaleTypes): float
    {
        $legacySalesQuery = Sale::where('store_id', $storeId)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->whereIn('sale_type', $includedSaleTypes)
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            });

        // توافق قديم: يحمي البيئات التي لم تطبق عمود sale_items.total_cost بعد.
        // خطة الحذف: بعد تثبيت بيانات التكلفة القديمة في بداية شهر 7 واعتماد العمود نهائيًا،
        // يمكن إزالة هذا الفرع مع إبقاء اختبار يؤكد ثبات تكلفة التقرير الشهري.
        if (! Schema::hasColumn('sale_items', 'total_cost')) {
            return (float) $legacySalesQuery
                ->selectRaw('COALESCE(SUM(products_total), 0) as products_cost')
                ->value('products_cost');
        }

        // بداية اعتماد تكلفة أسطر البيع المحفوظة. ما قبلها يبقى على fallback القديم لحماية التقارير التاريخية.
        // خطة الحذف: بعد التخلص من الحسابات القديمة في بداية شهر 7، يصبح المسار الأساسي هو item_total_cost،
        // ويمكن تحويل fallback إلى أداة ترحيل بيانات أو تقرير تدقيق بدل بقائه في الحساب اليومي.
        $costTrackingStart = Carbon::create(2026, 6, 1)->startOfDay()->toDateTimeString();

        $salesCostsQuery = DB::table('sales')
            ->leftJoin('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $storeId)
            ->whereBetween('sales.created_at', [$periodStart, $periodEnd])
            ->whereIn('sales.sale_type', $includedSaleTypes)
            ->where(function ($query) {
                $query->whereNull('sales.description')
                    ->orWhere('sales.description', '!=', 'manual_invoice_entry');
            })
            ->groupBy('sales.id', 'sales.products_total', 'sales.created_at')
            ->selectRaw('sales.id')
            ->selectRaw('CASE WHEN sales.created_at < ? THEN 1 ELSE 0 END as use_legacy_cost', [$costTrackingStart])
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(sale_items.total_cost, 0) > 0 THEN sale_items.total_cost ELSE 0 END), 0) as item_total_cost')
            ->selectRaw('COUNT(sale_items.id) as items_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(sale_items.total_cost, 0) > 0 THEN 1 ELSE 0 END) as costed_items_count')
            ->selectRaw('COALESCE(sales.products_total, 0) as legacy_products_cost');

        return (float) DB::query()
            ->fromSub($salesCostsQuery, 'sales_costs')
            ->selectRaw('COALESCE(SUM(CASE WHEN use_legacy_cost = 1 THEN legacy_products_cost WHEN items_count > 0 AND items_count = costed_items_count THEN item_total_cost ELSE legacy_products_cost END), 0) as total_cost')
            ->value('total_cost');
    }
}
