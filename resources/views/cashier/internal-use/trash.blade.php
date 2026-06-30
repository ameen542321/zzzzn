@extends('dashboard.app')
@section('title', 'سلة محذوفات الاستهلاك')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-6 text-right" dir="rtl">
    @if(session('success'))
        <div class="mb-4 p-3 bg-green-500/10 border border-green-500/30 rounded-xl text-green-400 text-sm flex items-center gap-2">
            <span>✅</span> {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-sm flex items-center gap-2">
            <span>⚠️</span> {{ session('error') }}
        </div>
    @endif

    <div class="mb-6 bg-gray-800/40 p-5 rounded-2xl border border-gray-700/60 shadow-lg">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-white">سلة محذوفات الاستهلاك</h1>
                <p class="text-gray-400 text-xs mt-1.5">{{ $store->name ?? 'المتجر' }} — تعرض مشتريات المالك المحذوفة ناعماً فقط.</p>
            </div>
            <a href="{{ route('user.stores.internal-use.report.view', $storeId) }}" class="text-center bg-gray-700/80 hover:bg-gray-600 text-gray-200 px-4 py-2.5 rounded-xl text-xs font-semibold transition">العودة للتقرير</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @forelse($deletedPurchases as $purchase)
            <div class="bg-gray-800/40 border border-gray-700/60 rounded-2xl p-4 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-white font-bold text-sm">{{ $purchase->purchase_name ?: 'مشتريات استهلاك' }}</p>
                        <p class="text-gray-400 text-xs mt-1">{{ $purchase->description ?: 'بدون ملاحظات' }}</p>
                    </div>
                    <div class="text-left shrink-0">
                        <p class="text-yellow-400 font-black font-mono">{{ number_format((float) $purchase->cost, 2) }}</p>
                        <span class="text-[10px] text-gray-500">ر.س</span>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-gray-700/40 pt-3">
                    <span class="text-gray-500 text-[11px] font-mono">حُذفت: {{ optional($purchase->deleted_at)->format('Y-m-d h:i A') }}</span>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('user.stores.internal-use.trash.restore', ['store' => $storeId, 'purchase' => $purchase->id]) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-lg text-xs font-bold transition">استعادة</button>
                        </form>
                        <form method="POST" action="{{ route('user.stores.internal-use.trash.force-delete', ['store' => $storeId, 'purchase' => $purchase->id]) }}" onsubmit="return confirm('سيتم حذف العملية نهائياً ولا يمكن استعادتها. هل أنت متأكد؟')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="bg-red-700 hover:bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold transition">حذف نهائي</button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="md:col-span-2 bg-gray-800/20 border border-dashed border-gray-700 rounded-2xl py-12 text-center">
                <p class="text-gray-400 text-sm">سلة المحذوفات فارغة حالياً.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $deletedPurchases->links() }}
    </div>
</div>
@endsection
