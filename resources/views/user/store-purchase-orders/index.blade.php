@extends('dashboard.app')
@section('title', 'طلبيات توريد')
@section('content')
@php
    $labels = [
        'draft' => 'مسودة',
        'sent' => 'مرسلة',
        'received' => 'تم الاستلام',
        'approved' => 'معتمدة',
        'cancelled' => 'ملغية'
    ];
    $badgeClasses = [
        'draft' => 'bg-gray-800 text-gray-300',
        'sent' => 'bg-blue-950 border border-blue-800 text-blue-200',
        'received' => 'bg-amber-950 border border-amber-800 text-amber-200',
        'approved' => 'bg-green-950 border border-green-800 text-green-200',
        'cancelled' => 'bg-red-950 border border-red-800 text-red-200'
    ];
@endphp
<div class="max-w-7xl mx-auto p-6 space-y-6" dir="rtl">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black text-white">طلبيات توريد</h1>
            <p class="text-gray-400 text-sm mt-1">يعرض افتراضيًا طلبيات الشهر الحالي فقط، ويمكنك تغيير الفترة من الفلتر.</p>
        </div>
        <a href="{{ route('user.stores.purchase-orders.create', $store->id) }}" class="px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold transition">+ طلبية جديدة</a>
    </div>

    @if($errors->any())
        <div class="rounded-xl border border-red-800 bg-red-950/40 p-4 text-red-200">
            {{ $errors->first() }}
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-xl border border-green-800 bg-green-950/40 p-4 text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 bg-gray-900/60 border border-gray-800 rounded-2xl p-3">
        <div>
            <label class="block text-xs text-gray-400 mb-1">من تاريخ</label>
            <input type="date" name="date_from" value="{{ $dateFromValue }}" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
        </div>
        <div>
            <label class="block text-xs text-gray-400 mb-1">إلى تاريخ</label>
            <input type="date" name="date_to" value="{{ $dateToValue }}" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white px-3 py-2 focus:outline-none focus:border-blue-600">
        </div>
        <div class="flex items-end gap-2">
            @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <button class="px-4 py-2 rounded-lg bg-blue-700 text-white font-bold hover:bg-blue-600 transition">تطبيق فلتر التاريخ</button>
            <a href="{{ route('user.stores.purchase-orders.index', ['store' => $store->id, 'status' => $status]) }}" class="px-4 py-2 rounded-lg bg-gray-800 text-gray-200 hover:bg-gray-700 transition">الشهر الحالي</a>
        </div>
    </form>

    <div class="flex flex-wrap gap-2 bg-gray-900/60 border border-gray-800 rounded-2xl p-3">
        <a href="{{ route('user.stores.purchase-orders.index', ['store' => $store->id, 'date_from' => $dateFromValue, 'date_to' => $dateToValue]) }}" class="px-4 py-2 rounded-lg text-sm font-bold transition {{ empty($status) ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">الكل</a>
        @foreach($labels as $value => $label)
            <a href="{{ route('user.stores.purchase-orders.index', ['store'=>$store->id,'status'=>$value,'date_from'=>$dateFromValue,'date_to'=>$dateToValue]) }}" class="px-4 py-2 rounded-lg text-sm font-bold transition {{ ($status ?? null) === $value ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($orders as $order)
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-5 hover:border-blue-600 transition">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <a href="{{ route('user.stores.purchase-orders.show', [$store->id, $order->id]) }}" class="flex-1">
                        <p class="text-white font-black hover:text-blue-400 transition">طلبية #{{ $order->id }} {{ $order->supplier_name ? '— '.$order->supplier_name : '' }}</p>
                        <p class="text-gray-400 text-sm mt-1">{{ $order->items_count }} منتج • {{ $order->created_at?->format('Y-m-d') }}</p>
                    </a>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="px-3 py-1 rounded-full text-xs font-bold {{ $badgeClasses[$order->status] ?? 'bg-gray-800 text-gray-200' }}">{{ $labels[$order->status] ?? $order->status }}</span>

                        @if(in_array($order->status, ['draft','sent'], true))
                            <form method="POST" action="{{ route('user.stores.purchase-orders.cancel', [$store->id, $order->id]) }}" onsubmit="return confirm('هل أنت متأكد من إلغاء هذه الطلبية؟')">
                                @csrf
                                <button class="px-3 py-1 rounded-lg bg-red-800/40 border border-red-600/50 text-red-100 text-xs font-bold hover:bg-red-700 transition">إلغاء الطلبية</button>
                            </form>
                        @elseif($order->status === 'cancelled')
                            <form method="POST" action="{{ route('user.stores.purchase-orders.destroy', [$store->id, $order->id]) }}" onsubmit="return confirm('هل أنت متأكد من حذف الطلبية الملغية نهائيًا؟ لا يمكن التراجع عن هذا الإجراء.')">
                                @csrf
                                @method('DELETE')
                                <button class="px-3 py-1 rounded-lg bg-gray-800 border border-gray-600 text-gray-100 text-xs font-bold hover:bg-red-900 hover:border-red-600 transition">حذف</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-gray-900 border border-gray-800 rounded-2xl p-10 text-center text-gray-400">لا توجد طلبيات توريد مطابقة للفلتر المحدد.</div>
        @endforelse
    </div>

    {{ $orders->appends(['status'=>$status,'date_from'=>$dateFromValue,'date_to'=>$dateToValue])->links() }}
</div>
@endsection
