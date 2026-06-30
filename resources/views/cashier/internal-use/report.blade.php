@extends('dashboard.app')
@section('title', 'تقرير الاستهلاك')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6 text-right" dir="rtl">
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

    <div class="mb-6 bg-gray-800/40 backdrop-blur-sm p-5 rounded-2xl border border-gray-700/60 shadow-lg">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-white tracking-tight">تقرير الاستهلاك</h1>
                <p class="text-gray-400 text-xs mt-1.5 flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    {{ $store->name ?? 'المتجر' }} — عمليات الاستهلاك منفصلة تماماً عن المصاريف المالية
                </p>
            </div>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <a href="{{ route('user.stores.show', $storeId) }}" class="flex-1 sm:flex-none text-center bg-gray-700/80 hover:bg-gray-600 text-gray-200 px-4 py-2.5 rounded-xl text-xs font-semibold transition">رجوع للمتجر</a>
                <a href="{{ route('user.stores.internal-use.trash', $storeId) }}" class="flex-1 sm:flex-none text-center bg-gray-800 hover:bg-gray-700 text-gray-200 px-4 py-2.5 rounded-xl text-xs font-semibold transition">سلة المحذوفات</a>
                <a href="{{ route('user.stores.internal-use.export-pdf', ['store' => $storeId, 'month' => $month, 'year' => $year]) }}" class="flex-1 sm:flex-none text-center bg-red-600/90 hover:bg-red-500 text-white px-4 py-2.5 rounded-xl text-xs font-semibold transition shadow-lg shadow-red-950/20">تصدير PDF</a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="lg:col-span-2 bg-gray-800/40 p-5 rounded-2xl border border-gray-700/60 shadow-sm">
            <h3 class="text-white font-bold text-sm mb-4 flex items-center gap-2">
                <span class="p-1 bg-green-500/10 text-green-400 rounded-lg">🛒</span> تسجيل مشتريات المالك للاستهلاك <span class="text-xs font-normal text-gray-400">(بدون خصم مخزون)</span>
            </h3>
            <form method="POST" action="{{ route('user.stores.internal-use.add-consumption.store', $storeId) }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="md:col-span-2">
                        <label class="text-xs text-gray-400 block mb-1">نوع المشتريات</label>
                        <input type="text" name="type" required list="ownerPurchaseTypes" class="w-full bg-gray-900 border border-gray-700/80 rounded-xl py-2 px-3 text-sm text-white focus:outline-none focus:border-blue-500/50 transition" placeholder="مثال: امواس / ربل / تضليل">
                        <datalist id="ownerPurchaseTypes">
                            @foreach(($ownerPurchaseTypeOptions ?? []) as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="text-xs text-gray-400 block mb-1">المبلغ</label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full bg-gray-900 border border-gray-700/80 rounded-xl py-2 pl-12 pr-3 text-sm text-white focus:outline-none focus:border-blue-500/50 transition" placeholder="0.00">
                            <span class="absolute left-3 top-2.5 text-xs text-gray-500">ر.س</span>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">ملاحظات</label>
                    <textarea name="description" rows="2" class="w-full bg-gray-900 border border-gray-700/80 rounded-xl py-2 px-3 text-sm text-white focus:outline-none focus:border-blue-500/50 transition" placeholder="تفاصيل إضافية عن المشتريات..."></textarea>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="bg-green-600 hover:bg-green-500 text-white px-6 py-2 rounded-xl text-xs font-bold transition shadow-md shadow-green-950/20">حفظ العملية</button>
                </div>
            </form>
        </div>

        <div class="bg-gray-800/40 p-5 rounded-2xl border border-gray-700/60 shadow-sm flex flex-col justify-between">
            <div>
                <h3 class="text-white font-bold text-sm mb-4 flex items-center gap-2">
                    <span class="p-1 bg-blue-500/10 text-blue-400 rounded-lg">📅</span> تحديد فترة التقرير
                </h3>
                <form method="GET" action="{{ route('user.stores.internal-use.report.view', $storeId) }}" class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs text-gray-400 block mb-1">الشهر</label>
                            <select name="month" class="w-full bg-gray-900 border border-gray-700/80 rounded-xl py-2 px-3 text-sm text-white focus:outline-none focus:border-blue-500/50 transition">
                                @for($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" @selected((int) $month === $m)>{{ $m }}</option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-gray-400 block mb-1">السنة</label>
                            <input type="number" name="year" value="{{ $year }}" min="2020" max="2100" class="w-full bg-gray-900 border border-gray-700/80 rounded-xl py-2 px-3 text-sm text-white focus:outline-none focus:border-blue-500/50 transition">
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white py-2.5 rounded-xl text-xs font-bold transition shadow-md shadow-blue-950/20">تحديث البيانات</button>
                </form>
            </div>
            <div class="mt-4 pt-3 border-t border-gray-700/40 text-center">
                <span class="text-xs text-gray-400">فترة التقرير الحالية:</span>
                <p class="text-white font-mono font-bold text-xs mt-1 bg-gray-900/50 py-1 px-2 rounded-lg inline-block">{{ $reportData['startDate'] }} ➔ {{ $reportData['endDate'] }}</p>
            </div>
        </div>
    </div>

    @if(($ownerPurchaseGroups ?? collect())->count() > 0)
        <div class="mb-6 bg-gray-800/30 p-4 rounded-2xl border border-gray-700/40">
            <h4 class="text-gray-400 font-bold text-xs mb-3 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> تجميع مشتريات المالك المتكررة هذا الشهر
            </h4>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($ownerPurchaseGroups as $group)
                    <div class="bg-gray-900/40 border border-gray-700/50 rounded-xl p-2.5 flex items-center justify-between">
                        <div>
                            <p class="text-emerald-400 font-bold text-xs">{{ $group['name'] }}</p>
                            <span class="text-[10px] text-gray-500">العمليات: {{ $group['count'] }}</span>
                        </div>
                        <p class="text-yellow-400 font-extrabold text-xs font-mono">{{ number_format($group['total'], 2) }} <span class="text-[9px] font-normal text-gray-400">ر.س</span></p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
        <div class="bg-gradient-to-br from-gray-800/40 to-gray-800/10 p-4 rounded-2xl border border-gray-700/50 shadow-sm relative overflow-hidden">
            <div class="absolute left-4 top-4 text-2xl text-blue-500/20">🧾</div>
            <p class="text-gray-400 text-xs font-medium">استهلاك المحاسب</p>
            <p class="text-blue-400 text-xl font-black font-mono mt-2">{{ number_format($reportData['summary']['accountant_total'], 2) }} <span class="text-xs font-bold text-gray-500">ر.س</span></p>
        </div>
        <div class="bg-gradient-to-br from-gray-800/40 to-gray-800/10 p-4 rounded-2xl border border-gray-700/50 shadow-sm relative overflow-hidden">
            <div class="absolute left-4 top-4 text-2xl text-emerald-500/20">🛒</div>
            <p class="text-gray-400 text-xs font-medium">مشتريات المالك للاستهلاك</p>
            <p class="text-emerald-400 text-xl font-black font-mono mt-2">{{ number_format($reportData['summary']['owner_total'], 2) }} <span class="text-xs font-bold text-gray-500">ر.س</span></p>
        </div>
        <div class="bg-gradient-to-br from-gray-800/50 to-gray-800/20 p-4 rounded-2xl border border-gray-700 shadow-sm relative overflow-hidden">
            <div class="absolute left-4 top-3 text-right">
                <span class="bg-yellow-500/10 text-yellow-400 text-[10px] px-2 py-0.5 rounded-full font-bold">{{ number_format($reportData['summary']['count']) }} عملية</span>
            </div>
            <p class="text-gray-400 text-xs font-medium">إجمالي الاستهلاك العام</p>
            <p class="text-yellow-400 text-xl font-black font-mono mt-2">{{ number_format($reportData['summary']['grand_total'], 2) }} <span class="text-xs font-bold text-gray-500">ر.س</span></p>
        </div>
    </div>

    <div class="space-y-3">
        <h3 class="text-white font-bold text-sm px-1 flex items-center gap-2">
            <span>📝</span> سجل العمليات والتفاصيل
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @forelse($reportData['records'] as $i => $row)
                <div class="bg-gray-800/40 border border-gray-700/60 rounded-2xl p-4 flex flex-col justify-between gap-4 shadow-sm hover:border-gray-600/80 transition relative overflow-visible">
                    <div class="flex justify-between items-start gap-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="text-xs font-mono bg-gray-900 text-gray-500 w-6 h-6 rounded-lg flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                            <div class="min-w-0">
                                @if(str_contains($row['source'], 'المحاسب'))
                                    <span class="bg-blue-500/10 text-blue-400 text-xs px-2 py-1 rounded-lg font-bold flex items-center gap-1 w-fit">
                                        <span>🧾</span> {{ $row['source'] }}
                                    </span>
                                @elseif(str_contains($row['source'], 'المالك'))
                                    <span class="bg-emerald-500/10 text-emerald-400 text-xs px-2 py-1 rounded-lg font-bold flex items-center gap-1 w-fit">
                                        <span>🛒</span> {{ $row['source'] }}
                                    </span>
                                @else
                                    <span class="bg-gray-700/50 text-gray-300 text-xs px-2 py-1 rounded-lg font-bold">
                                        {{ $row['source'] }}
                                    </span>
                                @endif
                                <p class="text-white font-bold text-sm mt-2 break-words">{{ $row['type'] }}</p>
                            </div>
                        </div>
                        <div class="text-left shrink-0">
                            <span class="text-yellow-400 font-black text-base font-mono block">{{ number_format($row['amount'], 2) }}</span>
                            <span class="text-[10px] text-gray-500 font-bold block">ر.س</span>
                        </div>
                    </div>

                    @if($row['description'] && $row['description'] !== '-')
                        <div class="bg-gray-900/40 p-2.5 rounded-xl border border-gray-700/30">
                            <p class="text-gray-400 text-xs leading-relaxed break-words">{{ $row['description'] }}</p>
                        </div>
                    @endif

                    <div class="pt-3 border-t border-gray-700/40 flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500 font-mono text-[11px]">
                            {{ \Carbon\Carbon::parse($row['created_at'])->format('Y-m-d h:i A') }}
                        </span>

                        <div class="flex items-center gap-3 shrink-0">
                            @if(($row['entry_type'] ?? null) === 'owner_purchase')
                                <details class="inline-block text-right relative dropdown-details">
                                    <summary class="cursor-pointer text-blue-400 hover:text-blue-300 font-bold select-none list-none">تعديل</summary>
                                    <div class="absolute left-0 bottom-full mb-2 bg-gray-900 border border-gray-700 rounded-xl p-3 w-64 space-y-2 shadow-2xl z-20">
                                        <form method="POST" action="{{ route('user.stores.internal-use.add-consumption.update', ['store' => $storeId, 'purchase' => $row['entry_id']]) }}" class="space-y-2">
                                            @csrf
                                            @method('PUT')
                                            <div>
                                                <label class="text-[10px] text-gray-400 block mb-0.5">النوع</label>
                                                <input type="text" name="type" value="{{ $row['type'] }}" class="w-full bg-gray-950 border border-gray-700 rounded-lg px-2 py-1 text-xs text-white focus:outline-none" required>
                                            </div>
                                            <div>
                                                <label class="text-[10px] text-gray-400 block mb-0.5">المبلغ</label>
                                                <input type="number" step="0.01" min="0.01" name="amount" value="{{ $row['amount'] }}" class="w-full bg-gray-950 border border-gray-700 rounded-lg px-2 py-1 text-xs text-white focus:outline-none" required>
                                            </div>
                                            <div>
                                                <label class="text-[10px] text-gray-400 block mb-0.5">الملاحظات</label>
                                                <textarea name="description" rows="2" class="w-full bg-gray-950 border border-gray-700 rounded-lg px-2 py-1 text-xs text-white focus:outline-none">{{ $row['description'] !== '-' ? $row['description'] : '' }}</textarea>
                                            </div>
                                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white text-xs rounded-lg py-1.5 font-bold transition">حفظ التعديل</button>
                                        </form>
                                    </div>
                                </details>

                                <form method="POST" action="{{ route('user.stores.internal-use.add-consumption.destroy', ['store' => $storeId, 'purchase' => $row['entry_id']]) }}" onsubmit="return confirm('هل أنت متأكد من حذف العملية؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-400 hover:text-red-300 font-bold">حذف</button>
                                </form>
                            @elseif(($row['entry_type'] ?? null) === 'accountant_internal_use')
                                <details class="inline-block text-right relative dropdown-details">
                                    <summary class="cursor-pointer text-indigo-400 hover:text-indigo-300 font-bold select-none list-none">تعديل</summary>
                                    <div class="absolute left-0 bottom-full mb-2 bg-gray-900 border border-gray-700 rounded-xl p-3 w-64 space-y-2 shadow-2xl z-20">
                                        <form method="POST" action="{{ route('user.stores.internal-use.accountant-consumption.update', ['store' => $storeId, 'sale' => $row['entry_id']]) }}" class="space-y-2">
                                            @csrf
                                            @method('PUT')
                                            <div>
                                                <label class="text-[10px] text-gray-400 block mb-0.5">الكمية</label>
                                                <input type="number" step="0.01" min="0.01" name="quantity" value="{{ $row['raw_quantity'] ?? 1 }}" class="w-full bg-gray-950 border border-gray-700 rounded-lg px-2 py-1 text-xs text-white focus:outline-none" required>
                                            </div>
                                            <div>
                                                <label class="text-[10px] text-gray-400 block mb-0.5">نوع الوحدة</label>
                                                <select name="unit_type" class="w-full bg-gray-950 border border-gray-700 rounded-lg px-2 py-1 text-xs text-white focus:outline-none">
                                                    @php($unitType = $row['raw_unit_type'] ?? 'default')
                                                    <option value="default" @selected($unitType === 'default')>افتراضي</option>
                                                    <option value="meters" @selected($unitType === 'meters')>متر</option>
                                                    <option value="roll" @selected($unitType === 'roll')>رول</option>
                                                    <option value="piece" @selected($unitType === 'piece')>حبة</option>
                                                    <option value="kit" @selected($unitType === 'kit')>طقم</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-[10px] text-gray-400 block mb-0.5">الملاحظات</label>
                                                <textarea name="internal_notes" rows="2" class="w-full bg-gray-950 border border-gray-700 rounded-lg px-2 py-1 text-xs text-white focus:outline-none" placeholder="ملاحظات">{{ $row['description'] !== '-' ? $row['description'] : '' }}</textarea>
                                            </div>
                                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white text-xs rounded-lg py-1.5 font-bold transition">تعديل الاستهلاك</button>
                                        </form>
                                    </div>
                                </details>

                                <form method="POST" action="{{ route('user.stores.internal-use.accountant-consumption.destroy', ['store' => $storeId, 'sale' => $row['entry_id']]) }}" onsubmit="return confirm('هل تريد حذف العملية واسترجاع المخزون؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-rose-400 hover:text-rose-300 font-bold">حذف واسترجاع</button>
                                </form>
                            @else
                                <span class="text-gray-600">-</span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-1 md:col-span-2 bg-gray-800/20 text-center py-12 rounded-2xl border border-dashed border-gray-700">
                    <p class="text-gray-400 text-sm">لا توجد عمليات استهلاك مسجلة في هذا الشهر.</p>
                </div>
            @endforelse
        </div>

        @if(method_exists($reportData['records'], 'links') && $reportData['records']->hasPages())
            <div class="mt-6">
                {{ $reportData['records']->links() }}
            </div>
        @endif
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.dropdown-details').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (!details.open) {
                return;
            }

            document.querySelectorAll('.dropdown-details[open]').forEach((opened) => {
                if (opened !== details) {
                    opened.removeAttribute('open');
                }
            });
        });
    });
});
</script>

<style>
    .dropdown-details summary::-webkit-details-marker { display: none; }
    .dropdown-details summary { list-style: none; }
</style>
@endsection
