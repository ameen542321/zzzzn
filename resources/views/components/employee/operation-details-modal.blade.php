<div id="{{ $modalId }}" class="hidden fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
    <div class="bg-gray-900 border border-gray-700 rounded-2xl w-full max-w-5xl max-h-[85vh] overflow-hidden shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
            <h2 class="text-xl font-bold text-gray-100">{{ $title }}</h2>
            <button type="button"
                    onclick="document.getElementById('{{ $modalId }}').classList.add('hidden')"
                    class="text-gray-400 hover:text-white text-2xl leading-none">&times;</button>
        </div>

        <div class="overflow-x-auto overflow-y-auto max-h-[68vh]">
            <table class="w-full text-sm text-right">
                <thead class="bg-gray-800/80 text-gray-300 sticky top-0">
                    <tr>
                        @foreach($columns as $label)
                            <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($rows as $row)
                        <tr class="hover:bg-gray-800/50 text-gray-200">
                            @foreach($columns as $key => $label)
                                <td class="px-4 py-3 align-top whitespace-nowrap">
                                    @switch($key)
                                        @case('amount')
                                        @case('remaining_amount')
                                            {{ number_format((float) ($row->{$key} ?? 0), 2) }} ريال
                                            @break

                                        @case('signed_amount')
                                            <span class="{{ (float) ($row->amount ?? 0) < 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                                {{ number_format((float) ($row->amount ?? 0), 2) }} ريال
                                            </span>
                                            @break

                                        @case('date')
                                            {{ optional($row->date)->format('Y-m-d') ?? $row->date ?? optional($row->created_at)->format('Y-m-d') ?? '-' }}
                                            @break

                                        @case('added_by')
                                            {{ $row->addedBy?->name ?? 'غير محدد' }}
                                            @break

                                        @case('partial_payments')
                                            @php($payments = collect($row->partial_payments ?? []))
                                            @if($payments->isEmpty())
                                                <span class="text-gray-500">لا توجد تحصيلات</span>
                                            @else
                                                <div class="space-y-1 whitespace-normal">
                                                    @foreach($payments as $payment)
                                                        <div class="text-xs text-gray-300">
                                                            {{ number_format((float) ($payment['amount'] ?? 0), 2) }} ريال
                                                            — {{ isset($payment['date']) ? \Carbon\Carbon::parse($payment['date'])->format('Y-m-d H:i') : '-' }}
                                                            — {{ $payment['added_by_name'] ?? 'غير محدد' }}
                                                            @if(!empty($payment['description']))
                                                                — {{ $payment['description'] }}
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @break

                                        @default
                                            <span class="whitespace-normal">{{ $row->{$key} ?: '-' }}</span>
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}" class="px-4 py-10 text-center text-gray-500">
                                لا توجد بيانات في الشهر المحدد
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
