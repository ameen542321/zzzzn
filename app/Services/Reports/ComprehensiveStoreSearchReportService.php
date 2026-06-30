<?php

namespace App\Services\Reports;

use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Store;

class ComprehensiveStoreSearchReportService
{
    /**
     * يبني بيانات تقرير البحث الشامل للمتجر بعيدًا عن StoreController.
     */
    public function build(Store $store, array $validated): array
    {
        $search = trim((string) ($validated['q'] ?? ''));
        $from = $validated['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $to = $validated['to'] ?? now()->format('Y-m-d');
        $scope = $validated['scope'] ?? 'all';
        $startDate = $from . ' 00:00:00';
        $endDate = $to . ' 23:59:59';

        $saleTypes = ['cash', 'card', 'credit', 'mixed'];

        $salesQuery = Sale::query()
            ->where('store_id', $store->id)
            ->whereIn('sale_type', $saleTypes)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->with([
                'accountant:id,name',
                'employee:id,name',
                'items.product:id,name,description,barcode',
            ]);

        if ($search !== '') {
            $salesQuery->where(function ($query) use ($search) {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhere('internal_notes', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($itemsQuery) use ($search) {
                        $itemsQuery->where('custom_name', 'like', "%{$search}%")
                            ->orWhereHas('product', function ($productQuery) use ($search) {
                                $productQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('description', 'like', "%{$search}%")
                                    ->orWhere('barcode', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $salesSummary = (clone $salesQuery)
            ->selectRaw('COUNT(*) as operations_count')
            ->selectRaw('COALESCE(SUM(products_total), 0) as products_total')
            ->selectRaw('COALESCE(SUM(labor_total), 0) as labor_total')
            ->selectRaw('COALESCE(SUM(COALESCE(final_total, total, 0)), 0) as final_total')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(cash_amount), 0) as cash_amount')
            ->selectRaw('COALESCE(SUM(card_amount), 0) as card_amount')
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) as remaining_amount')
            ->selectRaw('COALESCE(SUM(profit), 0) as profit')
            ->first();

        $matchingItemsQuery = \App\Models\SaleItem::query()
            ->whereHas('sale', function ($saleQuery) use ($store, $saleTypes, $startDate, $endDate) {
                $saleQuery->where('store_id', $store->id)
                    ->whereIn('sale_type', $saleTypes)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where(function ($query) {
                        $query->whereNull('description')
                            ->orWhere('description', '!=', 'manual_invoice_entry');
                    });
            });

        if ($search !== '') {
            $matchingItemsQuery->where(function ($itemsQuery) use ($search) {
                $itemsQuery->where('custom_name', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search) {
                        $productQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
            });
        }

        $matchingItemsSummary = $matchingItemsQuery
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->first();

        $internalUseQuery = Sale::query()
            ->where('store_id', $store->id)
            ->where('sale_type', 'internal_use')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function ($query) {
                $query->whereNull('description')
                    ->orWhere('description', '!=', 'manual_invoice_entry');
            })
            ->with([
                'accountant:id,name',
                'items.product:id,name,description,barcode',
            ]);

        if ($search !== '') {
            $internalUseQuery->where(function ($query) use ($search) {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhere('internal_notes', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($itemsQuery) use ($search) {
                        $itemsQuery->where('custom_name', 'like', "%{$search}%")
                            ->orWhereHas('product', function ($productQuery) use ($search) {
                                $productQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('description', 'like', "%{$search}%")
                                    ->orWhere('barcode', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $internalSummary = (clone $internalUseQuery)
            ->selectRaw('COUNT(*) as operations_count')
            ->selectRaw('COALESCE(SUM(COALESCE(total, final_total, 0)), 0) as total_cost')
            ->first();

        $ownerPurchasesQuery = Purchase::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('product:id,name,description,barcode');

        if ($search !== '') {
            $ownerPurchasesQuery->where(function ($query) use ($search) {
                $query->where('purchase_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search) {
                        $productQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
            });
        }

        $ownerPurchasesSummary = (clone $ownerPurchasesQuery)
            ->selectRaw('COUNT(*) as purchases_count')
            ->selectRaw('COALESCE(SUM(cost), 0) as total_cost')
            ->first();

        $summary = [
            'sales_total' => (float) ($salesSummary->final_total ?? 0),
            'sales_count' => (int) ($salesSummary->operations_count ?? 0),
            'products_total' => (float) ($salesSummary->products_total ?? 0),
            'labor_total' => (float) ($salesSummary->labor_total ?? 0),
            'paid_total' => (float) ($salesSummary->paid_amount ?? 0),
            'cash_total' => (float) ($salesSummary->cash_amount ?? 0),
            'card_total' => (float) ($salesSummary->card_amount ?? 0),
            'remaining_total' => (float) ($salesSummary->remaining_amount ?? 0),
            'profit_total' => (float) ($salesSummary->profit ?? 0),
            'matching_items_total' => (float) ($matchingItemsSummary->total ?? 0),
            'matching_items_quantity' => (float) ($matchingItemsSummary->quantity ?? 0),
            'internal_total' => (float) ($internalSummary->total_cost ?? 0),
            'internal_count' => (int) ($internalSummary->operations_count ?? 0),
            'owner_purchases_total' => (float) ($ownerPurchasesSummary->total_cost ?? 0),
            'owner_purchases_count' => (int) ($ownerPurchasesSummary->purchases_count ?? 0),
        ];

        $summary['net_after_internal'] = $summary['sales_total'] - $summary['internal_total'];
        $summary['net_after_internal_and_purchases'] = $summary['sales_total'] - $summary['internal_total'] - $summary['owner_purchases_total'];
        $summary['all_operations_count'] = $summary['sales_count'] + $summary['internal_count'] + $summary['owner_purchases_count'];
        $summary['all_operations_total'] = $summary['sales_total'] + $summary['internal_total'] + $summary['owner_purchases_total'];
        $summary['selected_operations_count'] = match ($scope) {
            'sales' => $summary['sales_count'],
            'internal' => $summary['internal_count'],
            'purchases' => $summary['owner_purchases_count'],
            default => $summary['all_operations_count'],
        };
        $summary['selected_operations_total'] = match ($scope) {
            'sales' => $summary['sales_total'],
            'internal' => $summary['internal_total'],
            'purchases' => $summary['owner_purchases_total'],
            default => $summary['all_operations_total'],
        };

        $unifiedOperations = collect();

        if (in_array($scope, ['all', 'sales'], true)) {
            $unifiedOperations = $unifiedOperations->merge(
                (clone $salesQuery)->latest()->limit(100)->get()->map(function ($sale) {
                    $itemsTitle = $sale->items
                        ->map(fn ($item) => $item->product->name ?? $item->custom_name ?? null)
                        ->filter()
                        ->implode('، ');
                    $itemsDetails = $sale->items
                        ->map(function ($item) {
                            $product = $item->product;
                            $parts = [
                                $product->name ?? $item->custom_name ?? 'منتج محذوف',
                                'الكمية: ' . number_format((float) $item->quantity, 2),
                                'السعر: ' . number_format((float) $item->price, 2),
                                'الإجمالي: ' . number_format((float) $item->total, 2),
                            ];

                            if (!empty($product?->barcode)) {
                                $parts[] = 'باركود: ' . $product->barcode;
                            }

                            if (!empty($product?->description)) {
                                $parts[] = 'وصف المنتج: ' . $product->description;
                            }

                            return implode(' | ', $parts);
                        })
                        ->implode(PHP_EOL);

                    return [
                        'type' => 'sale',
                        'type_label' => 'بيع',
                        'badge_class' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
                        'id' => $sale->id,
                        'date' => $sale->created_at,
                        'title' => $itemsTitle ?: ($sale->description ?: 'بيع'),
                        'details' => $itemsDetails ?: ($sale->description ?: 'لا يوجد تفاصيل'),
                        'amount' => (float) ($sale->final_total ?? $sale->total ?? 0),
                        'meta' => 'إجمالي الفاتورة كاملة | شغل يد: ' . number_format((float) $sale->labor_total, 2) . ' ر.س',
                    ];
                })
            );
        }

        if (in_array($scope, ['all', 'internal'], true)) {
            $unifiedOperations = $unifiedOperations->merge(
                (clone $internalUseQuery)->latest()->limit(100)->get()->map(function ($sale) {
                    return [
                        'type' => 'internal',
                        'type_label' => 'استهلاك',
                        'badge_class' => 'bg-yellow-500/10 text-yellow-300 border-yellow-500/30',
                        'id' => $sale->id,
                        'date' => $sale->created_at,
                        'title' => $sale->internal_notes ?: ($sale->description ?: 'استهلاك داخلي'),
                        'details' => $sale->items->map(fn ($item) => $item->product->name ?? $item->custom_name ?? 'منتج محذوف')->filter()->implode('، ') ?: 'لا يوجد تفاصيل',
                        'amount' => (float) ($sale->total ?? $sale->final_total ?? 0),
                        'meta' => 'لا يوجد تفاصيل',
                    ];
                })
            );
        }

        if (in_array($scope, ['all', 'purchases'], true)) {
            $unifiedOperations = $unifiedOperations->merge(
                (clone $ownerPurchasesQuery)->latest()->limit(100)->get()->map(function ($purchase) {
                    return [
                        'type' => 'purchase',
                        'type_label' => 'مشتريات مالك',
                        'badge_class' => 'bg-orange-500/10 text-orange-300 border-orange-500/30',
                        'id' => $purchase->id,
                        'date' => $purchase->created_at,
                        'title' => $purchase->purchase_name ?: ($purchase->product->name ?? 'مشتريات مالك'),
                        'details' => $purchase->description ?: ($purchase->product->name ?? 'لا يوجد تفاصيل'),
                        'amount' => (float) ($purchase->cost ?? 0),
                        'meta' => 'الكمية: ' . number_format((float) $purchase->quantity, 2),
                    ];
                })
            );
        }

        $unifiedOperations = $unifiedOperations
            ->sortByDesc(fn ($operation) => optional($operation['date'])->timestamp ?? 0)
            ->take(150)
            ->values();

        $summary['all_operations_count'] = $unifiedOperations->count();
        $summary['all_operations_total'] = (float) $unifiedOperations->sum('amount');
        $summary['selected_operations_count'] = $summary['all_operations_count'];
        $summary['selected_operations_total'] = $summary['all_operations_total'];

        return [
            'store' => $store,
            'search' => $search,
            'from' => $from,
            'to' => $to,
            'scope' => $scope,
            'summary' => $summary,
            'unifiedOperations' => $unifiedOperations,
        ];
    }
}
