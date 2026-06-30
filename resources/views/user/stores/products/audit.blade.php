@extends('dashboard.app')

@section('title', 'جرد المنتجات – ' . $store->name)

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6 text-right" dir="rtl">
    <div class="mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-clipboard-check text-emerald-400"></i>
                جرد المنتجات
            </h1>
            <p class="text-gray-400 text-sm mt-1">{{ $store->name }} — صفحة مستقلة لمتابعة حالة اكتمال بيانات الجرد.</p>
        </div>
        <a href="{{ route('user.stores.show', $store->id) }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-800 border border-gray-700 text-gray-200 hover:bg-gray-700 transition w-fit">
            <i class="fa-solid fa-arrow-right"></i>
            العودة للمتجر
        </a>
    </div>

    <div class="mb-6 rounded-2xl border border-gray-700 bg-gray-900/80 p-5">
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
            <div>
                <h2 class="text-white font-bold flex items-center gap-2">
                    <i class="fa-solid fa-circle-info text-blue-400"></i>
                    ملخص جرد المنتجات
                </h2>
                <p class="text-gray-400 text-xs mt-1">
                    دورة الجرد:
                    <span class="text-gray-200">{{ $inventoryAuditCycleStart->format('Y-m-d') }}</span>
                    <span class="text-gray-600 mx-1">إلى</span>
                    <span class="text-gray-200">{{ $inventoryAuditCycleEnd->format('Y-m-d') }}</span>
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 text-xs">
                <div class="rounded-xl bg-gray-950/60 border border-gray-700 px-4 py-3 text-gray-200">
                    <span class="block text-gray-400">الكل</span>
                    <b class="text-2xl text-white">{{ $inventoryAuditCounts['total'] ?? 0 }}</b>
                </div>
                <a href="{{ route('user.stores.products.audit', ['store' => $store->id, 'audit_status' => 'red', 'search' => $searchTerm]) }}" class="rounded-xl bg-red-500/10 border border-red-500/30 px-4 py-3 text-red-200 hover:bg-red-500/20 transition">
                    <span class="inline-flex items-center gap-1 font-bold"><span class="w-2 h-2 rounded-full bg-red-500"></span> أحمر: {{ $inventoryAuditCounts['red'] ?? 0 }}</span>
                    <p class="mt-1 text-[11px] text-red-100/80">بيانات ناقصة أو لم تدخل الكمية بعد.</p>
                </a>
                <a href="{{ route('user.stores.products.audit', ['store' => $store->id, 'audit_status' => 'yellow', 'search' => $searchTerm]) }}" class="rounded-xl bg-yellow-500/10 border border-yellow-500/30 px-4 py-3 text-yellow-100 hover:bg-yellow-500/20 transition">
                    <span class="inline-flex items-center gap-1 font-bold"><span class="text-yellow-300 text-base leading-none">●</span> أصفر: {{ $inventoryAuditCounts['yellow'] ?? 0 }}</span>
                    <p class="mt-1 text-[11px] text-yellow-50/80">مكتمل البيانات لكن لم يؤكد جرده في دورة الستة أشهر الحالية.</p>
                </a>
                <a href="{{ route('user.stores.products.audit', ['store' => $store->id, 'audit_status' => 'green', 'search' => $searchTerm]) }}" class="rounded-xl bg-green-500/10 border border-green-500/30 px-4 py-3 text-green-200 hover:bg-green-500/20 transition">
                    <span class="inline-flex items-center gap-1 font-bold"><span class="w-2 h-2 rounded-full bg-green-500"></span> أخضر: {{ $inventoryAuditCounts['green'] ?? 0 }}</span>
                    <p class="mt-1 text-[11px] text-green-100/80">تم تأكيد الجرد في دورة الستة أشهر الحالية.</p>
                </a>
            </div>
        </div>
    </div>

    <form method="GET" action="{{ route('user.stores.products.audit', $store->id) }}" class="mb-5 bg-gray-900/60 border border-gray-700 rounded-2xl p-4 flex flex-col lg:flex-row gap-3">
        <input type="text" name="search" value="{{ $searchTerm }}" placeholder="بحث باسم المنتج أو الوصف أو الباركود"
               class="flex-1 bg-gray-950 border border-gray-700 rounded-xl px-4 py-2.5 text-white text-sm">
        <select name="audit_status" class="bg-gray-950 border border-gray-700 rounded-xl px-4 py-2.5 text-white text-sm">
            <option value="">كل حالات الجرد</option>
            <option value="red" @selected($auditStatus === 'red')>أحمر — بيانات ناقصة</option>
            <option value="yellow" @selected($auditStatus === 'yellow')>أصفر — مكتمل دون تأكيد</option>
            <option value="green" @selected($auditStatus === 'green')>أخضر — مؤكد</option>
        </select>
        <button class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl text-sm font-bold">تطبيق</button>
        @if($searchTerm || $auditStatus)
            <a href="{{ route('user.stores.products.audit', $store->id) }}" class="bg-gray-800 hover:bg-gray-700 text-gray-200 px-5 py-2.5 rounded-xl text-sm text-center">مسح</a>
        @endif
    </form>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($products as $product)
            @php
                $audit = $product->inventoryAuditStatus($store);
                $dotClass = [
                    'red' => 'bg-red-500',
                    'yellow' => 'bg-yellow-300',
                    'green' => 'bg-green-500',
                ][$audit['color']] ?? 'bg-gray-500';
            @endphp
            <div class="bg-gray-900/70 border border-gray-700 rounded-2xl p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-white font-bold truncate flex items-center gap-2">
                            @if($audit['color'] === 'yellow')
                                <span class="rounded-full flex-shrink-0" style="width: 0.65rem; height: 0.65rem; background-color: #facc15;"></span>
                            @else
                                <span class="w-2 h-2 rounded-full {{ $dotClass }} flex-shrink-0"></span>
                            @endif
                            {{ $product->name }}
                        </h3>
                        <p class="text-gray-500 text-xs mt-1 truncate">{{ $product->category->name ?? 'غير مصنف' }}</p>
                    </div>
                    <a href="{{ route('user.stores.products.stock', ['store' => $store->id, 'product' => $product->id, 'return_to' => 'audit']) }}" class="text-xs bg-indigo-600/20 border border-indigo-500/30 text-indigo-300 px-3 py-1.5 rounded-lg hover:bg-indigo-600/30">إدارة المخزون</a>
                </div>
                <p class="text-gray-400 text-xs mt-3 leading-5">{{ $audit['message'] }}</p>
                <div class="mt-3 grid grid-cols-3 gap-2 text-[11px]">
                    <span class="bg-gray-950/60 border border-gray-800 rounded-lg p-2 text-gray-300">الكمية: <b class="text-white">{{ number_format((float) $product->quantity, 2) }}</b></span>
                    <span class="bg-gray-950/60 border border-gray-800 rounded-lg p-2 text-gray-300">البيع: <b class="text-blue-300">{{ number_format((float) $product->price, 2) }}</b></span>
                    <span class="bg-gray-950/60 border border-gray-800 rounded-lg p-2 text-gray-300">التكلفة: <b class="text-green-300">{{ number_format((float) ($product->cost_price ?? 0), 2) }}</b></span>
                </div>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3 bg-gray-900/60 border border-gray-700 rounded-2xl p-8 text-center text-gray-400">
                لا توجد منتجات مطابقة للبحث أو الفلتر الحالي.
            </div>
        @endforelse
    </div>
</div>
@endsection
