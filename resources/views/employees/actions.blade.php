@extends('dashboard.app')
@php
    $hasOldAccount = $employee->accountant ? true : false;
    $label = $hasOldAccount ? 'المحاسب' : 'الموظف';
@endphp

@section('title', $label . ' - ' . $employee->name)

@section('content')

<div class="px-6 py-8 max-w-5xl mx-auto">

    @php
        $hasOldAccount = $employee->accountant ? true : false;
        $label = $hasOldAccount ? 'المحاسب' : 'الموظف';
    @endphp

   <div class="flex items-center justify-between mb-10">

    {{-- زر الرجوع (يمين) --}}
    <a href="{{ $returnTo ?? request('return_to', route('user.employees.index')) }}"
       class="flex items-center gap-2 bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
        <i class="fa-solid fa-arrow-right text-lg"></i>
        <span>رجوع</span>
    </a>

    {{-- العنوان (وسط) --}}
    <div class="text-center flex-1">
        <h1 class="text-3xl font-bold text-gray-100">
            {{ $label }} — {{ $employee->name }}
        </h1>
        <p class="text-gray-400 mt-1 text-sm">
            عرض وإدارة بيانات المحاسب
        </p>
    </div>

    {{-- يسار فارغ للتوازن --}}
    <div class="w-24"></div>

</div>

    <form method="GET" action="{{ url()->current() }}" class="mb-6 rounded-2xl border border-gray-800 bg-gray-900/70 p-4 flex flex-col sm:flex-row gap-3 sm:items-end">
        <input type="hidden" name="return_to" value="{{ $returnTo ?? request('return_to') }}">
        <div>
            <label class="block text-xs text-gray-400 mb-2">فلترة الشهر</label>
            <input type="month" name="month" value="{{ $selectedMonth }}"
                   class="bg-gray-800 border border-gray-700 text-gray-100 rounded-lg px-3 py-2">
        </div>
        <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg">تطبيق</button>
    </form>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-8">
        @foreach($operationSummaryCards as $summaryCard)
            <button type="button"
                    onclick="document.getElementById('{{ $summaryCard['modal'] }}').classList.remove('hidden')"
                    class="text-right rounded-2xl border border-gray-800 bg-gray-900/70 p-4 shadow-lg hover:bg-gray-800/80 transition">
                <p class="text-xs text-gray-500 mb-2">{{ $summaryCard['label'] }}</p>
                <div class="flex items-end gap-1">
                    <span class="text-2xl font-black {{ $summaryCard['color'] }}">{{ $summaryCard['value'] }}</span>
                    <span class="text-[11px] text-gray-500 pb-1">{{ $summaryCard['suffix'] }}</span>
                </div>
                <p class="text-[11px] text-gray-500 mt-2">{{ $summaryCard['hint'] }}</p>
            </button>
        @endforeach
    </div>


    <!-- العمليات -->
    <div class="bg-gray-900/80 border border-gray-800 rounded-2xl p-6 md:p-7 shadow-xl backdrop-blur-sm">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl font-bold text-gray-100">عمليات الموظف</h2>

        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                        @foreach($actionCards as $card)
                @if(($card['type'] ?? 'modal') === 'link')
                    <a href="{{ $card['url'] }}"
                       class="group text-right bg-gray-800/70 hover:bg-gray-800 text-gray-100 rounded-xl border border-gray-700/80 p-5 transition-all hover:-translate-y-0.5 w-full">
                @else
                    <button type="button"
                            onclick="document.getElementById('{{ $card['modal'] }}').classList.remove('hidden')"
                            class="group text-right bg-gray-800/70 hover:bg-gray-800 text-gray-100 rounded-xl border border-gray-700/80 p-5 transition-all hover:-translate-y-0.5 w-full">
                @endif
                    <div class="flex items-center gap-3">
                        <span class="w-11 h-11 rounded-xl bg-{{ $card['accent'] }}-500/15 text-{{ $card['accent'] }}-300 flex items-center justify-center ring-1 ring-{{ $card['accent'] }}-500/30">
                            <i class="fa-solid {{ $card['icon'] }} text-lg"></i>
                        </span>
                        <div>
                            <p class="font-semibold">{{ $card['title'] }}</p>
                            <p class="text-xs text-gray-400 mt-1">{{ $card['hint'] }}</p>
                        </div>
                    </div>
                @if(($card['type'] ?? 'modal') === 'link')
                    </a>
                @else
                    </button>
                @endif
            @endforeach

            @if($employee->accountant && $employee->accountant->status === 'active')
                <form action="{{ route('user.employees.demote', $employee->id) }}" method="POST" class="sm:col-span-2 xl:col-span-3">
                    @csrf
                    <button class="w-full bg-gray-800 hover:bg-gray-700 text-gray-100 border border-orange-500/40 font-semibold py-3 rounded-xl transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-arrow-rotate-left text-orange-300"></i>
                        <span>إرجاع إلى موظف</span>
                    </button>
                </form>
            @else
                <button type="button"
                        onclick="document.getElementById('promoteModal').classList.remove('hidden')"
                        class="sm:col-span-2 xl:col-span-3 w-full bg-gray-800 hover:bg-gray-700 text-gray-100 border border-indigo-500/40 font-semibold py-3 rounded-xl transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-up text-indigo-300"></i>
                    <span>ترقية إلى محاسب</span>
                </button>
            @endif
        </div>
    </div>

    <!-- سجل العمليات -->
  <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 shadow-xl mt-12">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-10">
        <div>
            <h2 class="text-3xl font-bold text-gray-100">سجل العمليات</h2>
            <p class="text-gray-500 text-sm mt-1">آخر الأنشطة المسجلة على هذا الموظف</p>
        </div>

    </div>


    <div class="overflow-x-auto">
        <table class="w-full text-sm text-right">
            <thead class="text-gray-400 border-b border-gray-800">
                <tr>
                    <th class="py-3 px-2">العملية</th>
                    <th class="py-3 px-2">من قام بها</th>
                    <th class="py-3 px-2">النوع</th>
                    <th class="py-3 px-2">التاريخ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
    @forelse ($recentLogs as $log)

        @php
            $actionName = $log->action_name ?? $log->action ?? 'operation';
            $action = $logActionMap[$actionName] ?? [
                'label' => $actionName,
                'color' => 'text-gray-400',
                'icon'  => 'fa-circle-dot'
            ];
            $meta = is_array($log->meta) ? $log->meta : [];
            $actorName = $meta['actor_name'] ?? $meta['added_by_name'] ?? 'غير محدد';
            $typeLabel = $meta['type'] ?? 'عملية';
        @endphp

        <tr class="text-gray-200 hover:bg-gray-800/40">
            <td class="py-4 px-2">
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-gray-800 flex items-center justify-center">
                        <i class="fa-solid {{ $action['icon'] }} {{ $action['color'] }}"></i>
                    </span>
                    <div>
                        <p class="font-semibold {{ $action['color'] }}">{{ $action['label'] }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ $log->description }}</p>
                    </div>
                </div>
            </td>
            <td class="py-4 px-2 text-gray-300">{{ $actorName }}</td>
            <td class="py-4 px-2 text-gray-400">{{ $typeLabel }}</td>
            <td class="py-4 px-2 text-gray-500 whitespace-nowrap">{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
        </tr>

    @empty

        <tr>
            <td colspan="4" class="py-12 text-center text-gray-500">
                لا يوجد عمليات حتى الآن
            </td>
        </tr>

    @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $recentLogs->links() }}
    </div>

</div>




</div>


@include('components.employee.operation-details-modal', [
    'modalId' => 'withdrawalsDetailsModal',
    'title' => 'تفاصيل السحوبات للشهر المحدد',
    'rows' => $operationDetails['withdrawals'],
    'columns' => ['amount' => 'المبلغ', 'date' => 'التاريخ', 'added_by' => 'من أضافها', 'description' => 'الملاحظات'],
])
@include('components.employee.operation-details-modal', [
    'modalId' => 'debtsDetailsModal',
    'title' => 'تفاصيل المديونيات والتحصيلات للشهر المحدد',
    'rows' => $operationDetails['debts'],
    'columns' => ['signed_amount' => 'المبلغ', 'date' => 'التاريخ', 'added_by' => 'من أضافها', 'description' => 'الملاحظات'],
])
@include('components.employee.operation-details-modal', [
    'modalId' => 'creditSalesDetailsModal',
    'title' => 'تفاصيل البيع الآجل للشهر المحدد',
    'rows' => $operationDetails['credit_sales'],
    'columns' => ['amount' => 'القيمة', 'remaining_amount' => 'المتبقي', 'date' => 'التاريخ', 'added_by' => 'من أضافها', 'description' => 'الملاحظات', 'partial_payments' => 'التحصيلات'],
])
@include('components.employee.operation-details-modal', [
    'modalId' => 'absencesDetailsModal',
    'title' => 'تفاصيل الغياب للشهر المحدد',
    'rows' => $operationDetails['absences'],
    'columns' => ['date' => 'التاريخ', 'added_by' => 'من أضافها', 'description' => 'الملاحظات'],
])

<!-- المودالات -->
{{-- @include('components.employee.debt-operations-modal', ['person' => $employee]) --}}
@include('components.employee.withdrawal-form', ['person' => $employee])
@include('components.employee.absence-form', ['person' => $employee])
@include('components.employee.debt-form', ['person' => $employee])
@include('components.employee.credit-sale-form', ['person' => $employee])
@include('components.employee.credit-sale-collection', ['person' => $employee])


<!-- مودال الترقية -->
<div id="promoteModal" class="hidden fixed inset-0 bg-black/60 flex items-center justify-center z-50">
    <div class="bg-gray-900 border border-gray-700 rounded-xl p-8 w-full max-w-md">

        <h2 class="text-xl font-bold text-gray-100 mb-6">ترقية الموظف إلى محاسب</h2>

        <form action="{{ route('user.employees.promote', $employee->id) }}" method="POST">
            @csrf

            @if($employee->accountant)
                <div class="mb-4 p-3 bg-yellow-600 text-white rounded-lg">
                    هذا الموظف لديه بريد محاسب سابق. يمكنك فقط إعادة تعيين كلمة المرور أو تركها كما هي.
                </div>

                <label class="block text-gray-300 mb-2">البريد الإلكتروني (غير قابل للتعديل)</label>
                <input type="text"
                       value="{{ $employee->accountant->email }}"
                       readonly
                       class="w-full bg-gray-800 border border-gray-700 text-gray-400 rounded-lg p-3 mb-4 cursor-not-allowed select-all">

            @else
                <label class="block text-gray-300 mb-2">البريد الإلكتروني</label>
                <input type="email" name="email" id="emailInput"
                       pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"
                       class="w-full bg-gray-800 border border-gray-700 text-gray-200 rounded-lg p-3 mb-2"
                       placeholder="example@mail.com" required>

                <div id="emailExistsWarning"
                     class="hidden mb-4 p-3 bg-red-600 text-white rounded-lg text-sm">
                    هذا البريد مستخدم مسبقًا. الرجاء إدخال بريد آخر.
                </div>
            @endif

            <label class="block text-gray-300 mb-2">
                كلمة المرور @if($employee->accountant) <span class="text-gray-400">(اختياري)</span> @endif
            </label>

            <input type="password" name="password"
                   class="w-full bg-gray-800 border border-gray-700 text-gray-200 rounded-lg p-3 mb-4"
                   placeholder="********">

            <div class="flex items-center justify-between mt-6">
                <button type="button"
                        onclick="document.getElementById('promoteModal').classList.add('hidden')"
                        class="px-4 py-2 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600">
                    إلغاء
                </button>

                <button id="promoteSubmit"
                        type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    تأكيد الترقية
                </button>
            </div>
        </form>

    </div>
</div>


<!-- فحص الإيميل عبر AJAX -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const emailInput   = document.getElementById('emailInput');
    const warningBox   = document.getElementById('emailExistsWarning');
    const submitButton = document.getElementById('promoteSubmit');
    const form         = document.querySelector('#promoteModal form');

    // منع إرسال النموذج إذا كان الزر معطلاً
    if (form) {
        form.addEventListener('submit', function (e) {
            if (submitButton.disabled) {
                e.preventDefault();
            }
        });
    }

    // فحص البريد عبر AJAX
    if (emailInput) {
        emailInput.addEventListener('input', function () {

            // منع الرموز غير المسموح بها
            const invalidPattern = /[^a-zA-Z0-9@._\-+]/;
            if (invalidPattern.test(emailInput.value)) {
                warningBox.textContent = "البريد يحتوي على حروف أو رموز غير مسموح بها.";
                warningBox.classList.remove('hidden');
                submitButton.disabled = true;
                submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                return;
            }

            fetch("{{ route('user.employees.checkEmail') }}", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": "{{ csrf_token() }}"
                },
                body: JSON.stringify({ email: emailInput.value })
            })
            .then(res => res.json())
            .then(data => {
                if (data.exists) {
                    warningBox.textContent = "هذا البريد مستخدم مسبقًا. الرجاء إدخال بريد آخر.";
                    warningBox.classList.remove('hidden');
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                } else {
                    warningBox.classList.add('hidden');
                    submitButton.disabled = false;
                    submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            })
            .catch(() => {
                warningBox.classList.add('hidden');
                submitButton.disabled = false;
                submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
            });

        });
    }

});
</script>

@endsection
