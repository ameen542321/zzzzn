<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle ?? ('التقرير الشهري - ' . $store->name) }}</title>
    <style>
        @page { margin: 16mm 13mm; }

        :root {
            --ink: #111827;
            --muted: #6b7280;
            --line: #d9dde5;
            --panel: #f7f9fc;
            --accent: #2563eb;
            --ok: #047857;
            --bad: #b91c1c;
        }

        body {
            margin: 0;
            color: var(--ink);
            direction: rtl;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11.5px;
            line-height: 1.65;
            background: #fff;
        }

        .report-shell {
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }

        .report-head {
            padding: 14px 16px 12px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        }

        .title {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            color: #0f172a;
        }

        .meta {
            margin-top: 9px;
            width: 100%;
            border-collapse: collapse;
        }

        .meta td {
            width: 50%;
            padding: 6px 8px;
            border: 1px solid var(--line);
            background: #fff;
            font-size: 10.5px;
        }

        .meta strong { color: #374151; }

        .body-wrap { padding: 12px; }

        .kpi-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            margin-bottom: 8px;
        }

        .kpi-grid td {
            width: 25%;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--panel);
            padding: 8px 9px;
            vertical-align: top;
        }

        .kpi-label {
            color: var(--muted);
            font-size: 10px;
            margin-bottom: 3px;
        }

        .kpi-number {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .section {
            margin-top: 8px;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
        }

        .section-h {
            padding: 8px 11px;
            background: #eff6ff;
            border-bottom: 1px solid var(--line);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
        }

        .tbl {
            width: 100%;
            border-collapse: collapse;
        }

        .tbl th,
        .tbl td {
            border-bottom: 1px solid #eceff4;
            padding: 8px 10px;
            font-size: 11px;
            text-align: right;
        }

        .tbl th {
            width: 60%;
            color: #475569;
            font-weight: 600;
            background: #fcfdff;
        }

        .tbl tr:last-child th,
        .tbl tr:last-child td { border-bottom: 0; }

        .total-row {
            background: #f8fafc;
            font-weight: 700;
            border-top: 2px solid #d6dbe5;
        }

        .positive { color: var(--ok); }
        .negative { color: var(--bad); }

        .hint {
            margin-top: 10px;
            padding: 7px 10px;
            border-right: 3px solid var(--accent);
            background: #f8fbff;
            color: #334155;
            font-size: 10px;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
        }

        .detail-table th,
        .detail-table td {
            border-bottom: 1px solid #eceff4;
            padding: 6px 7px;
            font-size: 10px;
            text-align: right;
            vertical-align: top;
        }

        .detail-table th {
            color: #334155;
            background: #f8fafc;
            font-weight: 700;
        }

        .empty-row {
            color: var(--muted);
            text-align: center !important;
        }
    </style>
</head>
<body>
    <div class="report-shell">
        <div class="report-head">
            <h1 class="title">{{ $reportTitle ?? 'التقرير الشهري للمتجر' }}</h1>
            <table class="meta">
                <tr>
                    <td><strong>المتجر:</strong> {{ $store->name }}</td>
                    <td><strong>الشهر:</strong> {{ $month }}</td>
                </tr>
            </table>
        </div>

        <div class="body-wrap">
            <table class="kpi-grid">
                <tr>
                    <td>
                        <div class="kpi-label">إجمالي المبيعات</div>
                        <div class="kpi-number">{{ number_format($totalSales, 2) }} ر.س</div>
                    </td>
                    <td>
                        <div class="kpi-label">عدد العمليات</div>
                        <div class="kpi-number">{{ number_format($operationsCount) }}</div>
                    </td>
                    <td>
                        <div class="kpi-label">الكاش</div>
                        <div class="kpi-number">{{ number_format($cashSales, 2) }} ر.س</div>
                    </td>
                    <td>
                        <div class="kpi-label">الشبكة</div>
                        <div class="kpi-number">{{ number_format($cardSales, 2) }} ر.س</div>
                    </td>
                </tr>
            </table>

            <div class="section">
                <div class="section-h">التكاليف والاستهلاك</div>
                <table class="tbl">
                    <tr>
                        <th>الاستهلاك الداخلي (المحاسب)</th>
                        <td>{{ number_format($internalUseSales, 2) }} ر.س</td>
                    </tr>
                    <tr>
                        <th>مشتريات المالك للاستهلاك</th>
                        <td>{{ number_format($ownerPurchases, 2) }} ر.س</td>
                    </tr>
                    <tr>
                        <th>إجمالي الاستهلاك</th>
                        <td>{{ number_format($totalConsumption, 2) }} ر.س</td>
                    </tr>
                    <tr>
                        <th>تكلفة المنتجات المباعة (تخصم من الربح)</th>
                        <td>{{ number_format($profitDeductionTotal ?? $monthlySoldProductsCost, 2) }} ر.س</td>
                    </tr>
                    <tr>
                        <th>المصروفات</th>
                        <td>{{ number_format($expensesTotal, 2) }} ر.س</td>
                    </tr>
                    <tr>
                        <th>الرواتب الشهرية (للتوضيح فقط)</th>
                        <td>{{ number_format($monthlySalaries, 2) }} ر.س</td>
                    </tr>
                    <tr>
                        <th>سحبيات الموظفين (للتوضيح فقط)</th>
                        <td>{{ number_format($withdrawalsTotal, 2) }} ر.س</td>
                    </tr>
                    <tr class="total-row">
                        <th>{{ $netAfterCosts < 0 ? 'الخسارة بعد التكاليف' : 'صافي الربح بعد التكاليف' }}</th>
                        <td class="{{ $netAfterCosts >= 0 ? 'positive' : 'negative' }}">
                            @if($netAfterCosts < 0)
                                خسارة بمقدار {{ number_format(abs($netAfterCosts), 2) }} ر.س
                            @else
                                أرباح صافية بمقدار {{ number_format($netAfterCosts, 2) }} ر.س
                            @endif
                        </td>
                    </tr>
                </table>
            </div>



            @php
                $transferSummary = $transferSummary ?? [
                    'outgoing_count' => 0,
                    'incoming_count' => 0,
                    'outgoing_cost' => 0,
                    'incoming_cost' => 0,
                    'difference' => 0,
                    'difference_abs' => 0,
                    'difference_type' => 'balanced',
                    'difference_label' => 'متعادل',
                    'formula_note' => 'الفارق = إجمالي الوارد بسعر التكلفة - إجمالي الصادر بسعر التكلفة',
                ];
            @endphp
            <div class="section">
                <div class="section-h">ملخص النقل المخزني</div>
                <table class="tbl">
                    <tr>
                        <th>إجمالي المنتجات الصادرة بسعر التكلفة</th>
                        <td>{{ number_format($transferSummary['outgoing_cost'] ?? 0, 2) }} ر.س / {{ number_format($transferSummary['outgoing_count'] ?? 0) }} عملية</td>
                    </tr>
                    <tr>
                        <th>إجمالي المنتجات المستلمة بسعر التكلفة</th>
                        <td>{{ number_format($transferSummary['incoming_cost'] ?? 0, 2) }} ر.س / {{ number_format($transferSummary['incoming_count'] ?? 0) }} عملية</td>
                    </tr>
                    <tr class="total-row">
                        <th>الفارق الحسابي</th>
                        <td class="{{ ($transferSummary['difference_type'] ?? 'balanced') === 'profit' ? 'positive' : (($transferSummary['difference_type'] ?? 'balanced') === 'loss' ? 'negative' : '') }}">
                            {{ $transferSummary['difference_label'] ?? 'متعادل' }}: {{ number_format($transferSummary['difference_abs'] ?? abs($transferSummary['difference'] ?? 0), 2) }} ر.س
                        </td>
                    </tr>
                </table>
                <div class="hint">{{ $transferSummary['formula_note'] ?? 'الفارق = إجمالي الوارد بسعر التكلفة - إجمالي الصادر بسعر التكلفة' }}. إذا كان الصادر أعلى من الوارد يظهر كخسارة، وإذا كان الوارد أعلى من الصادر يظهر كربح.</div>
            </div>

            <div class="hint">
                طريقة الحساب: صافي النتيجة = المحصل - (تكلفة المنتجات المباعة + الاستهلاك الداخلي + مشتريات المالك للاستهلاك + المصروفات). تكلفة المنتجات المباعة هي تكلفة البضاعة التي تم بيعها وليست خصماً منفصلاً، والرواتب وسحبيات الموظفين قيم توضيحية فقط ولا تدخل في معادلة الربح.
            </div>

            @if($includeSalesDetails ?? false)
                <div class="section">
                    <div class="section-h">تفاصيل المبيعات اليومية</div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>عدد عمليات المبيعات</th>
                                <th>كاش</th>
                                <th>شبكة</th>
                                <th>الإجمالي العام</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($dailyRows ?? collect()) as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $row->day }}</td>
                                    <td>{{ number_format($row->ops_count) }}</td>
                                    <td>{{ number_format($row->cash_total ?? 0, 2) }} ر.س</td>
                                    <td>{{ number_format($row->card_total ?? 0, 2) }} ر.س</td>
                                    <td>{{ number_format($row->sales_total ?? 0, 2) }} ر.س</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="empty-row">لا توجد مبيعات يومية في هذا الشهر.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <div class="section-h">مشتريات المالك للاستهلاك</div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>السعر</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($ownerPurchaseRows ?? collect()) as $index => $purchase)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $purchase->created_at?->format('Y-m-d') }}</td>
                                    <td>{{ $purchase->product->name ?? $purchase->purchase_name ?? 'منتج' }}</td>
                                    <td>{{ number_format((float) ($purchase->quantity ?? 0), 2) }}</td>
                                    <td>{{ number_format((float) ($purchase->cost ?? 0), 2) }} ر.س</td>
                                    <td>{{ $purchase->description ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="empty-row">لا توجد مشتريات مالك للاستهلاك في هذا الشهر.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <div class="section-h">استهلاك المحاسب</div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المنتجات / الوصف</th>
                                <th>القيمة</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($accountantConsumptionRows ?? collect()) as $index => $sale)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $sale->created_at?->format('Y-m-d') }}</td>
                                    <td>
                                        @php
                                            $names = $sale->items->map(fn($item) => $item->product->name ?? 'منتج')->filter()->unique()->values();
                                        @endphp
                                        {{ $names->isNotEmpty() ? $names->implode('، ') : 'استهلاك داخلي' }}
                                    </td>
                                    <td>{{ number_format((float) ($sale->total ?? 0), 2) }} ر.س</td>
                                    <td>{{ $sale->description ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="empty-row">لا يوجد استهلاك محاسب في هذا الشهر.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <div class="section-h">المصروفات</div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>المبلغ</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($expenseRows ?? collect()) as $index => $expense)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $expense->created_at?->format('Y-m-d') }}</td>
                                    <td>{{ number_format((float) ($expense->amount ?? 0), 2) }} ر.س</td>
                                    <td>{{ $expense->description ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="empty-row">لا توجد مصروفات في هذا الشهر.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>



                <div class="section">
                    <div class="section-h">تفاصيل النقل المخزني</div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>النوع</th>
                                <th>المتجر المقابل</th>
                                <th>منتج المرسل</th>
                                <th>منتج المستلم</th>
                                <th>الكمية</th>
                                <th>التكلفة</th>
                                <th>الإجمالي</th>
                                <th>الحالة</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($transferRows ?? collect()) as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $row['date'] ?? $row['request_date'] }}</td>
                                    <td>{{ $row['direction'] }}</td>
                                    <td>{{ $row['other_store'] ?? '—' }}</td>
                                    <td>{{ $row['sender_product'] ?? '—' }}</td>
                                    <td>{{ $row['receiver_product'] ?? '—' }}</td>
                                    <td>{{ rtrim(rtrim(number_format($row['quantity'] ?? 0, 3, '.', ''), '0'), '.') }} {{ $row['unit_type'] }}</td>
                                    <td>{{ number_format($row['cost_price'] ?? 0, 2) }} ر.س</td>
                                    <td>{{ number_format($row['total_cost'] ?? 0, 2) }} ر.س</td>
                                    <td>{{ ['pending' => 'معلق', 'completed' => 'مكتمل', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'][$row['status']] ?? $row['status'] }}</td>
                                    <td>{{ $row['notes'] ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="empty-row">لا توجد عمليات نقل مخزني في هذا الشهر.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <div class="section-h">الرواتب والعمال</div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الشهر</th>
                                <th>العامل</th>
                                <th>الراتب الأساسي</th>
                                <th>أيام العمل</th>
                                <th>أيام الإيقاف</th>
                                <th>الراتب المستحق</th>
                                <th>السحوبات</th>
                                <th>الغيابات</th>
                                <th>المديونيات</th>
                                <th>المتبقي</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($employeeRows ?? collect()) as $index => $employee)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $employee['month'] }}</td>
                                    <td>{{ $employee['name'] }}</td>
                                    <td>{{ number_format($employee['base_salary'] ?? $employee['salary'], 2) }} ر.س</td>
                                    <td>{{ number_format($employee['worked_days'] ?? 0) }}</td>
                                    <td>{{ number_format($employee['suspended_days'] ?? 0) }}</td>
                                    <td>{{ number_format($employee['salary'], 2) }} ر.س</td>
                                    <td>{{ number_format($employee['withdrawals'], 2) }} ر.س</td>
                                    <td>{{ number_format($employee['absences_count']) }} / {{ number_format($employee['absence_penalty'], 2) }} ر.س</td>
                                    <td>{{ number_format($employee['debts'], 2) }} ر.س</td>
                                    <td>{{ number_format($employee['remaining'], 2) }} ر.س</td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="empty-row">لا يوجد عمال في هذا المتجر.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
