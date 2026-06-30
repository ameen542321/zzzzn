@extends('dashboard.app')
@section('title', 'إدارة الموظفين')
@section('content')

@php
    $activeAccountantsCount = $employees->filter(fn ($person) => $person->accountant && $person->accountant->status === 'active')->count();
@endphp

<div class="max-w-7xl mx-auto px-4 py-6 space-y-5">

    <div class="bg-gradient-to-r from-gray-900 to-gray-900/80 border border-gray-800 rounded-2xl p-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ request('return_to', url()->previous()) }}"
                   class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-700 text-gray-200 px-4 py-2 rounded-lg transition">
                    <i class="fa-solid fa-arrow-right"></i>
                    رجوع
                </a>

                <div>
                    <h1 class="text-2xl md:text-3xl font-black text-white">إدارة الموظفين</h1>
                    <p class="text-gray-400 text-sm mt-1">إدارة الموظفين والمحاسبين في متجرك بأسلوب أسرع وأوضح.</p>
                </div>
            </div>

            <a href="{{ route('user.employees.create', ['return_to' => url()->current()]) }}"
               class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-500 text-white px-5 py-3 rounded-xl font-bold shadow-lg shadow-blue-900/20 transition">
                <i class="fa-solid fa-plus"></i>
                إضافة موظف
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-gray-400 text-xs mb-1">إجمالي الموظفين</p>
            <p class="text-white text-2xl font-black">{{ $employees->count() }}</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-gray-400 text-xs mb-1">المحاسبون الفعّالون</p>
            <p class="text-emerald-400 text-2xl font-black">{{ $activeAccountantsCount }}</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-gray-400 text-xs mb-1">الموظفون بدون حساب محاسب</p>
            <p class="text-blue-400 text-2xl font-black">{{ max(0, $employees->count() - $activeAccountantsCount) }}</p>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="hidden md:grid grid-cols-12 px-4 py-3 text-xs font-bold text-gray-400 border-b border-gray-800 bg-gray-900/80">
            <div class="col-span-4">الاسم</div>
            <div class="col-span-2">الجوال</div>
            <div class="col-span-2">المتجر</div>
            <div class="col-span-2">الحالة</div>
            <div class="col-span-2 text-center">الإجراءات</div>
        </div>

        <div class="divide-y divide-gray-800">
            @forelse ($employees as $person)
                <div class="p-4 hover:bg-gray-800/30 transition">
                    <div class="hidden md:grid grid-cols-12 items-center gap-3">
                        <div class="col-span-4 flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-xl bg-gray-800 text-gray-300 flex items-center justify-center">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-white font-bold truncate">{{ $person->name }}</p>
                                <p class="text-gray-500 text-xs truncate">ID: {{ $person->id }}</p>
                                @if(isset($person->salary_info))
                                    <p class="text-[11px] text-amber-300 mt-1">
                                        راتب الشهر المتوقع: {{ number_format($person->salary_info['payable_salary'], 2) }} ر.س
                                        <span class="text-gray-500">({{ $person->salary_info['worked_days'] }} عمل / {{ $person->salary_info['suspended_days'] }} إيقاف)</span>
                                    </p>
                                @endif
                            </div>
                        </div>

                        <div class="col-span-2 text-gray-300 text-sm">{{ $person->phone ?? '—' }}</div>
                        <div class="col-span-2 text-gray-300 text-sm truncate">{{ $person->store->name }}</div>

                        <div class="col-span-2 flex flex-col items-center gap-1">
                            @if($person->status === 'active')
                                <span class="px-3 py-1 text-xs bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-full font-bold">موظف فعّال</span>
                            @else
                                <span class="px-3 py-1 text-xs bg-red-500/10 border border-red-500/30 text-red-300 rounded-full font-bold">موظف موقوف</span>
                            @endif
                            @if($person->accountant)
                                <span class="px-3 py-1 text-xs bg-amber-500/10 border border-amber-500/30 text-amber-300 rounded-full font-bold">{{ $person->accountant->status === 'active' ? 'محاسب فعّال' : 'محاسب موقوف' }}</span>
                            @endif
                        </div>

                        <div class="col-span-2">
                            <div class="flex items-center justify-center gap-3">
                                <a href="{{ route('user.employees.show', ['employee' => $person->id, 'return_to' => url()->current()]) }}" class="action-pill text-blue-300" title="عرض">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <a href="{{ route('user.employees.edit', ['employee' => $person->id, 'return_to' => url()->current()]) }}" class="action-pill text-amber-300" title="تعديل">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </a>

                                @if($person->status === 'active')
                                    <form action="{{ route('user.employees.suspend', $person->id) }}" method="POST" onsubmit="return confirm('سيتم إيقاف الموظف ماليًا ووظيفيًا، وسيتم إيقاف حساب المحاسب المرتبط إن وجد. لن يتم احتساب راتبه عن أيام الإيقاف. هل أنت متأكد؟')">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="return_to" value="{{ url()->current() }}">
                                        <button class="action-pill text-red-300" title="إيقاف الموظف"><i class="fa-solid fa-pause"></i></button>
                                    </form>
                                @else
                                    <form action="{{ route('user.employees.activate', $person->id) }}" method="POST" onsubmit="return confirm('سيتم تفعيل الموظف فقط واستئناف احتساب راتبه من تاريخ التفعيل، دون تفعيل حساب المحاسب. هل أنت متأكد؟')">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="return_to" value="{{ url()->current() }}">
                                        <button class="action-pill text-emerald-300" title="تفعيل الموظف"><i class="fa-solid fa-play"></i></button>
                                    </form>
                                @endif

                                <form action="{{ route('user.employees.destroy', $person->id) }}?return_to={{ urlencode(url()->current()) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="action-pill text-red-400" title="حذف">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="md:hidden space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="text-white font-bold">{{ $person->name }}</p>
                            <span class="text-xs text-gray-500">{{ $person->store->name }}</span>
                        </div>
                        <p class="text-sm text-gray-400">{{ $person->phone ?? '—' }}</p>
                        @if(isset($person->salary_info))
                            <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-100">
                                <p class="font-bold">راتب الشهر المتوقع: {{ number_format($person->salary_info['payable_salary'], 2) }} ر.س</p>
                                <p class="text-amber-200/80 mt-1">أيام العمل: {{ $person->salary_info['worked_days'] }} / أيام الإيقاف: {{ $person->salary_info['suspended_days'] }}</p>
                            </div>
                        @endif
                        <div class="flex items-center gap-2 flex-wrap">
                            @if($person->status === 'active')
                                <span class="px-2.5 py-1 text-[11px] bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-full font-bold">موظف فعّال</span>
                            @else
                                <span class="px-2.5 py-1 text-[11px] bg-red-500/10 border border-red-500/30 text-red-300 rounded-full font-bold">موظف موقوف</span>
                            @endif
                            @if($person->accountant)
                                <span class="px-2.5 py-1 text-[11px] bg-amber-500/10 border border-amber-500/30 text-amber-300 rounded-full font-bold">{{ $person->accountant->status === 'active' ? 'محاسب فعّال' : 'محاسب موقوف' }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 pt-1">
                            <a href="{{ route('user.employees.show', ['employee' => $person->id, 'return_to' => url()->current()]) }}" class="mobile-action text-blue-300">عرض</a>
                            <a href="{{ route('user.employees.edit', ['employee' => $person->id, 'return_to' => url()->current()]) }}" class="mobile-action text-amber-300">تعديل</a>
                            @if($person->status === 'active')
                                <form action="{{ route('user.employees.suspend', $person->id) }}" method="POST" onsubmit="return confirm('سيتم إيقاف الموظف ماليًا ووظيفيًا، وسيتم إيقاف حساب المحاسب المرتبط إن وجد. لن يتم احتساب راتبه عن أيام الإيقاف. هل أنت متأكد؟')">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="return_to" value="{{ url()->current() }}">
                                    <button class="mobile-action text-red-300">إيقاف الموظف</button>
                                </form>
                            @else
                                <form action="{{ route('user.employees.activate', $person->id) }}" method="POST" onsubmit="return confirm('سيتم تفعيل الموظف فقط واستئناف احتساب راتبه من تاريخ التفعيل، دون تفعيل حساب المحاسب. هل أنت متأكد؟')">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="return_to" value="{{ url()->current() }}">
                                    <button class="mobile-action text-emerald-300">تفعيل الموظف</button>
                                </form>
                            @endif
                            <form action="{{ route('user.employees.destroy', $person->id) }}?return_to={{ urlencode(url()->current()) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                                @csrf
                                @method('DELETE')
                                <button class="mobile-action text-red-400">حذف</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-12">لا يوجد أشخاص حتى الآن</div>
            @endforelse
        </div>
    </div>
</div>

<style>
    .action-pill {
        @apply inline-flex items-center justify-center w-9 h-9 rounded-lg bg-gray-800 hover:bg-gray-700 transition;
    }

    .mobile-action {
        @apply px-3 py-1.5 rounded-lg bg-gray-800 text-xs font-bold hover:bg-gray-700 transition;
    }
</style>
@endsection
