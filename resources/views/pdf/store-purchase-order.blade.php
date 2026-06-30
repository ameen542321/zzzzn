<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">

    <style>
        body{font-family:DejaVu Sans,Arial,sans-serif;direction:rtl;font-size:12px;color:#172033;margin:0;background:#fff}
        .page{padding:28px}
        .header{border:1px solid #d7deea;border-radius:14px;padding:18px;background:#f8fafc;margin-bottom:18px}
        .brand{font-size:24px;font-weight:800;color:#0f766e;letter-spacing:1px}.title{font-size:20px;font-weight:800;margin:10px 0 4px;color:#111827}
        .meta{width:100%;border-collapse:separate;border-spacing:0 8px;margin-top:10px}.meta td{padding:8px 10px;background:#fff;border:1px solid #e5e7eb}.label{color:#64748b;font-size:11px}.value{font-weight:700;color:#111827}
        table.items{width:100%;border-collapse:collapse;margin-top:12px}table.items th{background:#0f766e;color:#fff;padding:10px;border:1px solid #0f766e}table.items td{padding:9px;border:1px solid #d8dee9;vertical-align:top}table.items tr:nth-child(even) td{background:#f9fafb}
        .muted{color:#64748b}.blank{color:#9ca3af}.footer{position:fixed;bottom:18px;left:28px;right:28px;border-top:1px solid #e5e7eb;padding-top:8px;color:#64748b;font-size:11px;text-align:center}.signatures{margin-top:28px;width:100%;border-collapse:collapse}.signatures td{width:50%;padding:18px;border:1px dashed #cbd5e1;text-align:center;color:#475569}
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="brand">CARLED</div>
        <div class="title">سجل استلام طلبية رقم {{ $order->id }}</div>
        <div class="muted">ورقة تشغيلية لمقارنة تكلفة النظام بسعر الموزع وتسجيل الكمية الفعلية قبل الإدخال النهائي.</div>
        <table class="meta">
            <tr>
                <td><div class="label">المتجر</div><div class="value">{{ $store->name }}</div></td>
                <td><div class="label">المورد / المندوب</div><div class="value">{{ $order->supplier_name ?: 'غير محدد' }}</div></td>
                <td><div class="label">تاريخ الطلبية</div><div class="value">{{ optional($order->created_at)->format('Y-m-d') }}</div></td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead><tr><th>#</th><th>المنتج</th><th>الكمية المطلوبة</th><th>تكلفة النظام</th><th>الكمية المستلمة</th><th>سعر الاستلام الفعلي</th><th>ملاحظات</th></tr></thead>
        <tbody>
        @foreach($order->items as $index => $item)
            @php
                // تحديد تسمية الوحدة الافتراضية بناءً على نوع المنتج في المخزن ليتطابق مع شاشة البطاقات
                $currentUnit = $item->unit_type;
                if (!$currentUnit && $item->product) {
                    if ((($item->product->product_type ?? null) === 'fractional') || (float) $item->product->roll_length > 0) {
                        $currentUnit = 'roll';
                    } elseif ($item->product->is_splittable) {
                        $currentUnit = 'kit';
                    } else {
                        $currentUnit = 'unit';
                    }
                }
                $unitLabel = in_array($currentUnit, ['meter','meters']) ? 'متر' : ($currentUnit === 'piece' ? 'حبة' : ($currentUnit === 'roll' ? 'رول' : ($currentUnit === 'kit' ? 'طقم' : '')));
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td><strong>{{ $item->productName() }}</strong></td>
                <td>{{ (float)$item->quantity_requested > 0 ? number_format($item->quantity_requested, 2) : 'غير محدد' }} {{ $unitLabel }}</td>
                <td>{{ number_format((float)$item->cost_price_at_order, 2) }} ر.س</td>
                <td class="blank">________</td>
                <td class="blank">________ ر.س</td>
                <td>{{ $item->receipt_notes ?: '________' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="signatures"><tr><td>توقيع المستلم</td><td>توقيع الموزع</td></tr></table>
</div>
<div class="footer">تم إصدار التقرير بواسطة نظام CARLED</div>
</body>
</html>
