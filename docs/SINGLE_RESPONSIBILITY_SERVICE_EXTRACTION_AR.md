# الأعمال المتبقية لتطبيق مبدأ Single Responsibility واستخراج الخدمات

> **آخر تحديث:** 2026-06-30
> **ملاحظة مراجعة:** تمت إزالة البنود التي ثبت وجودها في الكود من قائمة التنفيذ المتبقي، وبقيت البنود التي تحتاج استخراجًا أو توحيدًا إضافيًا.

## ما اكتمل وتم حذفه من نطاق التنفيذ المتبقي

تمت مراجعة الخدمات الحالية، وثبت اكتمال أو بدء اعتماد الخدمات التالية لذلك لم تعد تُعامل كأسماء مقترحة فقط:

- `StoreAccessService` لإدارة وصول المالك للمتجر والتحقق من الحالة.
- `ShiftLifecycleService` لتحديد سياق الشفت والتاريخ المحاسبي.
- `ShiftWindowService` لتوحيد نوافذ الشفت في شاشة المبيعات والتقارير المرتبطة.
- `ShiftGapRequestService` لإدارة طلبات الشفت الناقص وحالاتها.
- `SalesCostService` كمصدر مركزي مرحلي لتكلفة المبيعات مع fallback للبيانات القديمة.
- `ShiftOperationBinderService` كبداية لعزل ربط المبيعات والمصروفات والسحوبات بالشفت ونقل تاريخها من الكنترولرات.
- خدمات تقارير وبحث منفصلة مثل `MonthlyStoreReportService` و`ComprehensiveStoreSearchReportService` و`RecentReportFilesService`.
- `AccountingOperationPresenter` و`AccountingOperationFeedService` كبداية لعزل عرض العمليات المحاسبية.

**النتيجة:** المتبقي ليس إنشاء هذه الأسماء من الصفر، بل استكمال نقل الاستدعاءات إليها، حذف التكرار القديم، وإضافة اختبارات تثبيت حولها.

## الهدف

هذا المستند يوثق نتيجة النقاش حول تضخم بعض الكنترولرات، خصوصًا:

- `app/Http/Controllers/Accountant/DashboardController.php`
- `app/Http/Controllers/StoreController.php`
- `app/Http/Controllers/DailySalesController.php`
- `app/Http/Controllers/Users/UserDashboardController.php`

والهدف هو وضع تصور عملي لتطبيق مبدأ **Single Responsibility Principle (SRP)** واستخراج خدمات مشتركة وخدمات منفصلة تقلل التكرار وتمنع اختلاف النتائج بين الصفحات والتقارير.

---

## الخلاصة المباشرة

نعم، يمكن تطبيق مبدأ **Single Responsibility** في المشروع، بل أصبح مناسبًا جدًا الآن بسبب تضخم الكنترولرات ووجود منطق مالي وتشغيلي متكرر في أكثر من مكان.

لكن التطبيق الصحيح لا يكون بنقل الكود كما هو إلى Services فقط، بل بتقسيم المسؤوليات إلى طبقات واضحة:

1. **Controller**
   - يستقبل الطلب.
   - ينفذ validation بسيط.
   - يستدعي Service.
   - يرجع View أو Redirect أو JSON.

2. **Service**
   - يحتوي منطق العمل الحقيقي.
   - مثال: إغلاق شفت، حساب تكلفة، إنشاء طلب مراجعة، نقل تاريخ شفت.

3. **Query Service / Repository خفيف**
   - مسؤول عن الاستعلامات المعقدة والمتكررة.
   - مثال: فلترة العمليات حسب `business_date` أو `daily_balance_id`.

4. **Presenter / ViewModel**
   - مسؤول عن تجهيز البيانات للعرض.
   - مثال: تجهيز بطاقة الموازنة، تفاصيل العمليات، صفوف تنبيهات الشفت.

5. **Policy / Authorization**
   - مسؤول عن الصلاحيات.
   - مثال: هل المالك يملك هذا المتجر؟ هل المحاسب تابع لهذا المتجر؟

---

## لماذا SRP مناسب هنا؟

### 1. لأن `StoreController` يقوم بأكثر من مسؤولية

`StoreController` لا يدير المتاجر فقط، بل يحتوي حاليًا على منطق يتعلق بـ:

- إدارة المتجر.
- حالة المتجر.
- تعيين المتجر الحالي.
- طلبات مراجعة الشفت.
- إغلاق صفري أو إغلاق مالك.
- نقل تاريخ الشفت.
- تقارير شهرية.
- تقارير بحث شامل.
- إحصائيات متقدمة.
- حساب تكلفة مبيعات.
- رواتب موظفين شهرية.

هذا يعني أن سبب تغيير الكنترولر أصبح واسعًا جدًا. أي تعديل في الشفتات أو التقارير أو التكلفة أو الموظفين قد يفرض تعديل نفس الكنترولر.

### 2. لأن `Accountant\DashboardController` ليس Dashboard فقط

كنترولر لوحة المحاسب لا يعرض الصفحة الرئيسية فقط، بل يحتوي على:

- عرض لوحة المحاسب.
- تفعيل طلب يوم مرجع.
- إغلاق طلب يوم مرجع.
- إلغاء وضع اليوم المرجع.
- عرض تقرير.
- إصدار الموازنة.
- حساب إحصاءات المبيعات.
- تجهيز تفاصيل عمليات الشفت.
- توليد بيانات تقرير الشفت.
- ربط العمليات بالشفت.
- حساب تكلفة المنتجات.
- تحديد منفذ العملية.

لذلك الأفضل أن يصبح الكنترولر منسقًا فقط، لا منفذًا لكل منطق الشفت والتكلفة والتقارير.

---

## قاعدة التطبيق

لا نبدأ بتقسيم كل شيء مرة واحدة. نبدأ بالكتل التي تتكرر أو تسبب أخطاء كثيرة:

1. الشفتات.
2. التكلفة.
3. التقارير.
4. الموظفون التاريخيون.
5. عرض العمليات.
6. صلاحيات المتجر والمحاسب.

---

## أنواع الخدمات المقترحة

سيكون في المشروع نوعان من الخدمات:

### 1. خدمات مشتركة Shared Services

هذه خدمات تستخدم في أكثر من مكان، مثل:

- `SalesCostService`
- `AccountingPeriodQueryService`
- `ShiftOperationBinderService`
- `EmployeeHistoricalStoreService`
- `StoreAccessService`
- `OperationPresenterService`

### 2. خدمات خاصة بتدفق معين Feature Services

هذه خدمات تخدم شاشة أو عملية محددة، مثل:

- `AccountantDashboardService`
- `StoreMonthlyReportService`
- `ShiftGapRequestService`
- `OwnerShiftCloseService`
- `QuickSaleService`
- `DailySalesReportService`

---

## الخدمات المقترحة بالتفصيل

### 1. `StoreAccessService`

#### المسؤولية

كل ما يتعلق بالسؤال:

- هل المالك يملك هذا المتجر؟
- هل المتجر فعال؟
- هل يمكن استخدام المتجر في الشفتات؟
- هل يظهر المتجر في النافبار أو التقارير؟

#### أمثلة دوال

```php
StoreAccessService::activeStoresForOwner(User $user)
StoreAccessService::ensureOwnerCanAccess(User $user, Store $store)
StoreAccessService::ensureStoreIsActive(Store $store)
StoreAccessService::canUseInShiftWorkflow(Store $store): bool
```

---

### 2. `ShiftLifecycleService`

#### الحالة الحالية

هذه الخدمة موجودة بالفعل، ويجب أن تبقى مختصة بدورة حياة الشفت فقط.

#### المسؤولية الصحيحة

- تحديد التاريخ المحاسبي الحالي.
- تحديد رقم الشفت الحالي.
- تحديد الأيام الناقصة.
- تحديد عدد الشفتات المطلوب لكل يوم.
- منع اعتبار اليوم الحالي ناقصًا.

#### ما لا يجب أن تحتويه

لا يجب أن تحتوي على:

- إنشاء طلب مراجعة للمحاسب.
- إرسال إشعار.
- بناء كروت الواجهة.
- توليد تقرير PDF.
- حساب التكلفة.

---

### 3. `ShiftGapService`

#### المسؤولية

تحويل مخرجات `ShiftLifecycleService` إلى صفوف مراجعة قابلة للعرض.

#### أمثلة دوال

```php
ShiftGapService::rowsForStore(Store $store)
ShiftGapService::rowForDate(Store $store, string $businessDate)
ShiftGapService::nextMissingShift(Store $store, string $businessDate)
ShiftGapService::closedShiftsCount(Store $store, string $businessDate)
```

---

### 4. `ShiftGapRequestService`

#### المسؤولية

إدارة طلبات مراجعة الشفت:

- إنشاء طلب.
- إلغاء طلب.
- إعادة تعيين الطلب لمحاسب آخر.
- تفعيل الطلب من المحاسب.
- تعليم الطلب كمحلول بعد الإغلاق.
- منع التكرار لنفس `business_date + shift_number`.

#### أمثلة دوال

```php
ShiftGapRequestService::create(Store $store, Accountant $accountant, string $date, int $shiftNumber)
ShiftGapRequestService::cancel(Store $store, string $date, int $shiftNumber, User $owner)
ShiftGapRequestService::reassign(Store $store, string $date, int $shiftNumber, Accountant $accountant)
ShiftGapRequestService::activeRequest(Store $store, string $date, int $shiftNumber)
ShiftGapRequestService::markResolved(Log $request, DailyBalance $balance)
```

---

### 5. `ShiftOperationBinderService`

#### المسؤولية

ربط العمليات بالشفت.

#### الاستخدامات المتوقعة

- ربط العمليات بالشفت حسب `business_date`.
- ربط العمليات بالشفت حسب نافذة وقتية.
- إغلاق المالك لشفت يحتوي عمليات.
- نقل تاريخ شفت وتحديث عمليات الشفت.
- معالجة العمليات القديمة غير المرتبطة.

#### أمثلة دوال

```php
ShiftOperationBinderService::attachByBusinessDate(DailyBalance $balance, string $date)
ShiftOperationBinderService::attachByWindow(DailyBalance $balance, Carbon $start, Carbon $end, string $date)
ShiftOperationBinderService::moveBalanceOperations(DailyBalance $balance, string $targetDate)
ShiftOperationBinderService::countUnclosedOperations(Store $store, string $date): array
```

---

### 6. `AccountingPeriodQueryService`

#### المسؤولية

توحيد استعلامات الفترة المحاسبية:

- هل نستخدم `daily_balance_id`؟
- هل نستخدم `business_date`؟
- هل نرجع إلى `created_at` للبيانات القديمة؟
- كيف نفلتر المبيعات والمصروفات والسحوبات بنفس القاعدة؟

#### أمثلة دوال

```php
AccountingPeriodQueryService::applySalesPeriod(Builder $query, AccountingPeriod $period)
AccountingPeriodQueryService::applyExpensesPeriod(Builder $query, AccountingPeriod $period)
AccountingPeriodQueryService::applyWithdrawalsPeriod(Builder $query, AccountingPeriod $period)
AccountingPeriodQueryService::fromBalance(DailyBalance $balance): AccountingPeriod
AccountingPeriodQueryService::fromBusinessDate(Store $store, string $date): AccountingPeriod
```

---

### 7. `SalesCostService`

#### المسؤولية

حساب التكلفة من مصدر واحد.

#### الهدف

توحيد مصدر التكلفة حول `sale_items.total_cost` قدر الإمكان، مع fallback واضح ومركزي للعمليات القديمة فقط.

#### أمثلة دوال

```php
SalesCostService::costForSale(Sale $sale): float
SalesCostService::costForPeriod(AccountingPeriod $period, array $saleTypes): float
SalesCostService::profitForSale(Sale $sale): float
SalesCostService::isLegacyCost(Sale $sale): bool
```

---

### 8. `CashReconciliationService`

#### المسؤولية

توحيد:

- إجمالي المبيعات.
- النقد.
- الشبكة.
- المكس.
- الآجل والتحصيل.
- المصروفات.
- السحوبات.
- المتوقع في الصندوق.
- الفرق بين المتوقع والفعلي.

#### أمثلة دوال

```php
CashReconciliationService::summaryForPeriod(AccountingPeriod $period): CashSummary
CashReconciliationService::expectedCash(CashSummary $summary): float
CashReconciliationService::difference(float $actual, float $expected): float
```

---

### 9. `EmployeeHistoricalStoreService`

#### المسؤولية

معرفة الموظف لأي متجر ينتمي خلال فترة تاريخية.

#### الهدف

إذا كان الموظف في متجر 1 في شهر 5 ثم انتقل في شهر 6 إلى متجر 2، فيجب أن تبقى بياناته في تقارير شهر 5 للمتجر 1.

#### أمثلة دوال

```php
EmployeeHistoricalStoreService::employeesForStoreDuringPeriod(int $storeId, Carbon $start, Carbon $end)
EmployeeHistoricalStoreService::belongedToStore(Employee $employee, int $storeId, Carbon $date): bool
EmployeeHistoricalStoreService::storeForEmployeeAt(Employee $employee, Carbon $date): ?Store
```

---

### 10. `EmployeePayrollService`

#### المسؤولية

تجميع راتب الموظف، سحوباته، غياباته، ديونه، وصافي راتبه.

#### أمثلة دوال

```php
EmployeePayrollService::summaryForEmployee(Employee $employee, Carbon $start, Carbon $end)
EmployeePayrollService::summaryForStore(int $storeId, Carbon $start, Carbon $end)
EmployeePayrollService::netSalary(EmployeePayrollSummary $summary): float
```

---

### 11. `OperationPresenterService`

#### المسؤولية

تجهيز العملية للعرض:

- نوع العملية.
- اسم المنفذ.
- الوصف.
- الأيقونة.
- اللون.
- المبلغ.
- هل هي بيع أو مصروف أو سحب أو تحصيل؟

#### أمثلة دوال

```php
OperationPresenterService::present($operation): OperationViewData
OperationPresenterService::typeLabel($operation): string
OperationPresenterService::actorName($operation): string
OperationPresenterService::description($operation): string
```

---

## كيف يصبح شكل الكنترولر بعد SRP؟

### مثال: طلب مراجعة شفت للمحاسب

بدل أن يحتوي الكنترولر على التحقق، وجلب المحاسب، وفحص التاريخ، وحساب رقم الشفت، وإنشاء السجل، وإرسال الإشعار، يصبح دوره تنسيقيًا فقط:

```php
public function requestAccountantShiftInput(Request $request, Store $store)
{
    $data = $request->validate([
        'business_date' => 'required|date',
        'accountant_id' => 'required|integer',
        'missing_shift_number' => 'nullable|integer|min:1|max:2',
    ]);

    $this->storeAccess->ensureOwnerCanAccess(auth()->user(), $store);
    $this->storeAccess->ensureStoreIsActive($store);

    $this->shiftGapRequests->createFromOwnerRequest(
        owner: auth()->user(),
        store: $store,
        accountantId: (int) $data['accountant_id'],
        businessDate: $data['business_date'],
        shiftNumber: $data['missing_shift_number'] ?? null,
    );

    return back()->with('success', 'تم إرسال طلب المراجعة للمحاسب.');
}
```

---

## ترتيب التنفيذ المقترح

### المرحلة 1: الخدمات المشتركة الحرجة

ابدأ بما يقلل اختلاف الأرقام ويمنع أخطاء التقارير:

1. استكمال ربط كل التقارير والصفحات بـ `SalesCostService` ثم حذف حسابات التكلفة المكررة.
2. إنشاء `AccountingPeriodQueryService` أو Scopes مكافئة لكل العمليات التي لم تنتقل بعد.
3. استكمال اعتماد `ShiftOperationBinderService` في أي مسارات متبقية وإضافة اختبارات تثبيت له.

هذه الخدمات تؤثر على:

- صفحة المبيعات اليومية.
- إغلاق الشفت.
- التقارير الشهرية.
- PDF و WhatsApp.
- إغلاق المالك.
- البيانات القديمة.

---

### المرحلة 2: خدمات الشفت والطلبات

4. توسيع خدمات فجوات الشفت الموجودة لتغطي العرض والتنبيهات دون تكرار.
5. استكمال اعتماد `ShiftGapRequestService` في كل مسارات الإنشاء والإلغاء وإعادة التعيين.
6. إنشاء `CashReconciliationService`.

هذه تنظف تضخم `DashboardController` و`StoreController`.

---

### المرحلة 3: الموظفون والتقارير التاريخية

7. `EmployeeHistoricalStoreService`
8. `EmployeePayrollService`
9. `StoreMonthlyReportService`

هذه تحل مشكلة انتقال الموظف من متجر إلى آخر وعدم تخريب تقارير الشهور السابقة.

---

### المرحلة 4: خدمات العرض

10. `OperationPresenterService`
11. `AccountantDashboardViewService`
12. `DailySalesViewService`

هذه تنظف الكنترولرات من تجهيز الواجهات والكروت والـ arrays الكبيرة.

---

## متى نحول الكود إلى Service؟

نحول الكود إلى Service إذا كان:

- يتكرر في أكثر من مكان.
- يحتوي قاعدة عمل مهمة.
- يؤثر على المال أو التقارير.
- يحتاج اختبارًا مستقلًا.
- يصعب فهمه داخل الكنترولر.
- قد يتغير مستقبلًا.

ولا نحوله إلى Service إذا كان:

- تنسيقًا بسيطًا جدًا خاصًا بواجهة واحدة.
- استعلامًا صغيرًا لا يتكرر.
- كودًا مؤقتًا داخل صفحة واحدة.
- مجرد `return view(...)`.

---

## الخلاصة النهائية

نعم، نستطيع تطبيق **Single Responsibility** في المشروع، والتطبيق أصبح ضروريًا لتقليل التضخم والتكرار.

الخطة الأفضل هي:

1. البدء بالخدمات المشتركة التي تمنع اختلاف الأرقام:
   - التكلفة.
   - الفترة المحاسبية.
   - ربط العمليات بالشفت.

2. بعدها نقل منطق الشفتات والطلبات:
   - تنبيهات الشفت.
   - طلبات المالك للمحاسب.
   - الإلغاء وإعادة التعيين.

3. ثم نقل تقارير الموظفين التاريخية:
   - أثر نقل الموظف.
   - الراتب.
   - الغيابات.
   - السحوبات.
   - الديون.

4. وأخيرًا تنظيف العرض:
   - تجهيز بيانات لوحة المحاسب.
   - تفاصيل العمليات.
   - كروت التقارير.

بهذا الشكل لن تكون الكنترولرات الكبيرة مسؤولة عن كل شيء، وسيصبح تعديل النظام أسهل وأكثر أمانًا وقابلًا للاختبار.

---

## قواعد إصلاح شاملة لجميع ملفات المشروع

هذه القواعد لا تخص ملفًا واحدًا أو الفرع الحالي فقط، بل تعتبر معيارًا عامًا عند مراجعة أي جزء من المشروع. الهدف هو منع تضخم الكنترولرات، منع اختلاف الحسابات، وتحسين قابلية الاختبار والصيانة.

### 1. قواعد عامة للطبقات والمسؤوليات

- الكنترولر لا يحمل منطقًا تجاريًا عميقًا؛ دوره استقبال الطلب، التحقق الأولي، استدعاء خدمة، ثم إرجاع استجابة.
- أي حساب مالي أو تشغيلي يتكرر أو يؤثر على التقارير يجب نقله إلى Service واضحة.
- أي استعلام معقد أو متكرر يجب نقله إلى Query Service أو Model Scope.
- أي بيانات تُجهز للعرض بكثرة يجب نقلها إلى Presenter أو ViewModel/DTO.
- أي تكامل خارجي مثل WhatsApp أو PDF أو تخزين ملفات يجب عزله خلف Service أو Job قابل لإعادة المحاولة.
- لا تُستخدم الجلسة `session()` مباشرة داخل منطق مالي أو تشغيلي عميق؛ إذا احتجنا جلسة، تغلف داخل خدمة صغيرة واضحة.
- لا يتم تنفيذ عمليات خارجية طويلة أو غير قابلة للـ rollback داخل معاملة قاعدة بيانات حرجة.
- الاستثناءات العامة `catch (\Exception)` تبقى كحماية أخيرة فقط، أما الحالات المتوقعة فتُعبّر عنها باستثناءات مخصصة أو نتائج واضحة.

### 2. قواعد الاستعلامات والأداء

- لا نستخدم `get()` على بيانات قابلة للنمو إلا مع حد واضح أو pagination أو chunk/lazy loading.
- يمنع جلب سجلات كبيرة ثم عمل pagination في الذاكرة إذا كان يمكن ترقيمها من قاعدة البيانات.
- يجب استخدام eager loading للعلاقات التي ستعرض داخل الحلقات.
- يمنع تنفيذ `Model::find()` أو استعلام جديد داخل `map` أو `foreach` لبيانات كثيرة؛ يتم جلب العلاقات أو IDs دفعة واحدة.
- شروط الفلاتر المتكررة مثل `store_id`, `business_date`, `daily_balance_id`, `created_at fallback`, وحالات العمليات توضع في Scopes أو Query Services.
- تقارير PDF والتقارير الشهرية تستخدم Query Services أو Report Services بدل بناء كل الاستعلامات داخل الكنترولر.

### 3. قواعد الحسابات المالية والتكلفة

- مصدر التكلفة الرسمي للعمليات الجديدة يكون من تكلفة سطر البيع المحفوظة، وليس من تكلفة المنتج الحالية عند عرض التقرير.
- أي fallback للبيانات القديمة يجب أن يكون مركزيًا داخل `SalesCostService` أو خدمة تكلفة مشابهة.
- لا يكرر أي تقرير معادلة التكلفة بنفسه.
- المتوقع في الصندوق، النقد، الشبكة، المكس، الآجل، التحصيل، المصروفات والسحوبات تُحسب من خدمة واحدة مثل `CashReconciliationService`.
- أي تعديل على منطق التكلفة أو الصندوق يجب أن ينعكس تلقائيًا على لوحة المحاسب، تقرير الإغلاق، تقرير المتجر الشهري، صفحة المبيعات اليومية، وPDF.

### 4. قواعد الشفتات والتاريخ المحاسبي

- أي منطق متعلق بـ `business_date`, `daily_balance_id`, رقم الشفت، الأيام الناقصة، أو عدد الشفتات المطلوب لا يكتب داخل الواجهة أو الكنترولر مباشرة.
- منطق دورة حياة الشفت يبقى في `ShiftLifecycleService` أو خدمات شفت متخصصة.
- ربط العمليات بالشفت أو نقل تاريخ الشفت يجب أن يتم من خدمة واحدة مثل `ShiftOperationBinderService`.
- طلبات المالك للمحاسب، إلغاء الطلب، إعادة التعيين، وتحديث الحالة تكون في `ShiftGapRequestService`.
- تقارير قديمة لا تملك `business_date` أو `daily_balance_id` تستخدم fallback واضح ومركزي، ولا تُربط عشوائيًا بدون قاعدة موثقة.

### 5. قواعد الملفات والتكاملات الخارجية

- إنشاء PDF وإرسال WhatsApp أو أي خدمة خارجية يجب أن يكون عبر Job أو Service مستقلة، وليس داخل الكنترولر مباشرة.
- مفاتيح وتوكنات الخدمات الخارجية يجب أن تكون في `config` و`.env`، وليس داخل الدوال.
- أسماء الملفات تنظف دائمًا قبل التخزين، ولا تستخدم الملاحظات أو مدخلات المستخدم مباشرة في اسم ملف أو رابط.
- يفضل تخزين التقارير في `storage` مع route محمي بدل روابط عامة مباشرة إذا كان التقرير يحتوي بيانات مالية أو شخصية.
- أي اتصال خارجي يجب أن يحتوي timeout وretry وسياسة فشل واضحة.

---

## قواعد إصلاح الواجهة لجميع ملفات Blade

هذه القواعد تشمل واجهات لوحة المحاسب، لوحة المالك، صفحات المتجر، صفحة المبيعات اليومية، البيع السريع، التقارير، وأي Blade جديد.

### 1. المشاكل المتكررة في الواجهة

- وجود منطق وقت وتنسيق وتواريخ داخل العرض مثل `Carbon::parse(...)->format(...)` يجعل الواجهة مسؤولة عن formatting بدل أن تستقبل قيمة جاهزة.
- حقن متغيرات كثيرة في JavaScript مباشرة يجعل الاختبار أصعب ويربط الواجهة بتفاصيل الكنترولر.
- HTML ضخم ومتكرر مثل بطاقات الإحصاء، المودالات، جداول العمليات، وصفوف التنبيهات يصعب صيانته إذا بقي داخل ملف واحد.
- وجود شروط عمل داخل العرض مثل إظهار أقسام بناءً على قيم مالية أو حالة شفت يجب أن يتحول إلى flags جاهزة من Presenter أو ViewModel.
- استخدام `now()` داخل Blade قد يسبب عدم اتساق بين وقت عرض الصفحة ووقت البيانات المحسوبة؛ الأفضل تمرير الوقت من الكنترولر أو الخدمة.
- العرض يجب ألا يخلط بين presentation وbusiness logic وformatting.
- Blade يقوم بالهروب تلقائيًا في `{{ }}`، لكن يجب الحذر من أي `raw HTML`, `{!! !!}`, أو روابط مبنية من ملاحظات أو أسماء ملفات.
- أي نمط UI يتكرر أكثر من مرة يجب أن يتحول إلى partial أو Blade component.

### 2. قواعد تجهيز البيانات للعرض

- كل تنسيق تواريخ وأرقام وتسميات وحالات يجب أن يجهز في Service/Presenter قبل الوصول إلى Blade.
- الواجهة تستقبل أسماء واضحة مثل `$shiftStats`, `$reportSummary`, `$operationRows`, `$balanceCard` بدل عشرات المتغيرات المنفصلة.
- بدل تمرير قيم كثيرة إلى inline JS، يتم تمرير كائن JSON واحد منسق أو تحميل البيانات عبر endpoint عند الحاجة.
- أي شرط معقد داخل Blade يحول إلى Boolean جاهز مثل `showCashCollections`, `hasPendingShiftRequest`, `canCloseShift`, `hasOperations`.
- لا تستخدم `Carbon::parse`, `now`, أو حسابات مالية داخل Blade إلا في حالات بسيطة جدًا ومبررة.

### 3. قواعد تقسيم واجهات Blade

الأجزاء التالية مرشحة للاستخراج إلى partials أو components:

- بطاقة إحصائية: `dashboard/_stat-card.blade.php`
- مودال العمليات: `dashboard/_operations-modal.blade.php`
- بطاقة شفت: `dashboard/_shift-card.blade.php`
- صف عملية: `dashboard/_operation-row.blade.php`
- بطاقة طلب من المالك: `dashboard/_owner-request-card.blade.php`
- ملخص الموازنة: `dashboard/_balance-summary.blade.php`
- جدول عمليات قابل للترقيم: `dashboard/_paginated-operations.blade.php`

يمكن لاحقًا تحويلها إلى Blade Components مثل:

```blade
<x-dashboard.stat-card />
<x-dashboard.operations-modal />
<x-shifts.shift-card />
<x-reports.balance-summary />
```

### 4. قواعد JavaScript والتفاعل

- المودالات الكبيرة لا تحمل كل بيانات العمليات مع الصفحة إذا كانت البيانات قابلة للنمو؛ الأفضل تحميلها عبر AJAX عند فتح المودال.
- أي Alpine component أو JS component يستقبل props واضحة أو endpoint، وليس عشرات المتغيرات العالمية.
- SweetAlert أو أي confirmation يجب أن يعتمد على data attributes أو component موحد بدل تكرار سكربت لكل نموذج.
- لا تخلط الحسابات المالية داخل JS إذا كانت النتائج يجب أن تطابق التقارير؛ الحسابات تأتي جاهزة من الخدمة.
- في حالة الحاجة إلى تحديث مباشر، يتم استدعاء endpoint يعيد DTO/JSON موحدًا.

### 5. قواعد الأداء وتجربة المستخدم في الواجهة

- بيانات العمليات الثقيلة تُحمّل عند الحاجة، خصوصًا تفاصيل الشفت أو سجلات طويلة.
- بطاقات الإحصاء الثقيلة يمكن تخزينها مؤقتًا بمفتاح يعتمد على `store_id`, `business_date`, `daily_balance_id`, أو `start_time`.
- الجداول الطويلة تستخدم pagination حقيقي أو lazy-loading، وليس إخفاء/إظهار عدد كبير محمل مسبقًا.
- الواجهات الكبيرة تقسم إلى أقسام قابلة لإعادة الاستخدام حتى لا يصبح ملف Blade صعب القراءة.

### 6. قواعد الأمان في الواجهة

- يمنع استخدام `{!! !!}` إلا عند الضرورة وبعد تنظيف المحتوى.
- أي ملاحظات، أسماء ملفات، روابط تقارير، أو نصوص قادمة من المستخدم يجب عرضها escaped أو تنظيفها قبل استخدامها.
- الروابط التي تعرض ملفات مالية أو تقارير يجب أن تمر عبر route يتحقق من الصلاحيات إذا أمكن.
- لا يتم بناء روابط ملفات حساسة مباشرة من مدخلات المستخدم داخل Blade.

### 7. اختبارات الواجهة

- لكل صفحة كبيرة يجب إضافة Feature Test بسيط يتأكد من ظهور الأقسام الرئيسية.
- يجب اختبار أن الصفحة تعرض القيم المجهزة من Presenter/DTO، لا أن Blade يحسبها بنفسه.
- يجب اختبار حالات مهمة مثل:
  - لا توجد عمليات.
  - توجد عمليات كثيرة.
  - يوجد طلب شفت من المالك.
  - لا يوجد محاسب فعال.
  - متجر موقوف لا يظهر في القوائم التشغيلية.

---

## ترتيب إصلاح الواجهة المقترح

1. استخراج ملخص الموازنة وبطاقة الإحصاء من لوحة المحاسب إلى partials/components.
2. استخراج مودال تفاصيل عمليات الشفت إلى component منفصل، ثم جعله يحمل البيانات عند الفتح إذا أصبحت البيانات كبيرة.
3. استخراج بطاقات تنبيهات الشفت وطلبات المالك إلى components مشتركة بين لوحة المالك وصفحة مراجعة الشفتات.
4. إزالة أي `Carbon::parse`, `now`, أو `number_format` ثقيل من Blade تدريجيًا وتحويله إلى Presenter.
5. تحويل المتغيرات الكثيرة في الصفحات الكبيرة إلى DTOs واضحة مثل `DashboardViewData`, `ShiftSummaryViewData`, `OperationRowViewData`.
6. إضافة اختبارات render أساسية للصفحات الكبيرة بعد كل استخراج.

---

## قاعدة نهائية معتمدة

أي ملف في المشروع، سواء كان Controller أو Blade أو Service أو Report، يخضع لهذه القاعدة:

> إذا كان الكود يكرر منطقًا، أو يحسب قيمة مالية، أو يقرر حالة شفت، أو يبني تقريرًا، أو يجهز عرضًا معقدًا، فلا يترك مبعثرًا داخل الملف؛ بل ينقل إلى طبقة مناسبة يمكن اختبارها وإعادة استخدامها.

بهذه القاعدة يصبح الإصلاح شاملًا للمشروع كله، مع بقاء التنفيذ تدريجيًا وآمنًا حتى لا تتأثر البيانات القديمة أو تدفقات البيع والإغلاق الحالية.

---

## دليل عمل قابل للنقل لأي فرع أو طلب سحب جديد

هذا القسم مصمم ليكون قابلاً للاستخدام عند تغيير الفرع، فتح مهمة جديدة، أو إنشاء طلب سحب جديد. عند بدء أي جولة refactor، لا تعتمد على تفاصيل الفرع الحالي فقط؛ استخدم هذا الدليل كقائمة فحص عامة على ملفات المشروع.

### نطاق الفحص الأساسي

عند مراجعة أي ملف أو مجموعة ملفات، ركز على المحاور التالية:

1. انتهاك مبدأ المسؤولية الواحدة.
2. التكرار في الاستعلامات والمنطق.
3. أداء الصفحة وذاكرة الاستعلامات.
4. منطق الأعمال داخل الكنترولر أو Blade.
5. استخدام الجلسة `session()` داخل منطق قابل للاختبار أو منطق مالي/تشغيلي.
6. التكاملات الخارجية مثل PDF وWhatsApp والتخزين.
7. قابلية الاختبار وإمكانية كتابة Unit/Feature tests.

---

## قائمة العيوب والمشاكل التي يجب البحث عنها

### 1. انتهاك مبدأ المسؤولية الواحدة

ابحث عن الملفات التي تجمع أكثر من مسؤولية مثل:

- Controller يعرض الصفحة، ويحسب الأرقام، ويجهز التقرير، ويرسل WhatsApp، ويدير session.
- Controller يدير CRUD وفي نفس الوقت يبني PDF أو تقرير شهري.
- View يحتوي formatting وحسابات وشروط عمل.
- Service واحد يحتوي أكثر من مجال عمل غير مترابط.

#### علامة خطر

إذا كان سبب تعديل الملف يمكن أن يكون: شفتات، تقارير، تكلفة، PDF، واجهة، صلاحيات، جلسة، وموظفين؛ فالملف غالبًا يخالف SRP.

### 2. التكرار في الاستعلامات والمنطق

ابحث عن:

- نفس شروط `Sale`, `Expense`, `Withdrawal` تتكرر في أكثر من دالة.
- نفس شرط `store_id`, `status`, `business_date`, `daily_balance_id`, أو `created_at` مكتوب يدويًا في أكثر من مكان.
- نفس حساب التكلفة أو المتوقع في الصندوق في أكثر من تقرير.
- نفس تجهيز صف العملية أو اسم المنفذ في أكثر من صفحة.
- نفس منطق المتجر الفعال أو المحاسب الفعال في Controller وBlade.

### 3. أداء الصفحة وذاكرة الاستعلامات

ابحث عن:

- `get()` بدون حد واضح على بيانات قابلة للنمو.
- `get()->map()->filter()->sort()` على مجموعات كبيرة كان يمكن معالجتها في قاعدة البيانات.
- pagination بعد الجلب في الذاكرة بدل pagination من SQL.
- استعلامات داخل `foreach` أو `map` مثل `Model::find()`.
- تحميل تفاصيل ثقيلة مع الصفحة رغم أنها لا تظهر إلا عند فتح modal.
- تحميل كل العمليات أو كل العلاقات في Dashboard واحد.

### 4. منطق الأعمال داخل الكنترولر

ابحث عن:

- حساب مبيعات، تكلفة، ربح، expected cash داخل Controller.
- بناء تقرير PDF داخل Controller.
- ربط عمليات بشفت داخل Controller.
- نقل تاريخ شفت وتعديل سجلات متعددة داخل Controller.
- بناء DTO يدوي كبير داخل Controller بدل Presenter/Service.
- استعلامات SQL خام تمثل مفهومًا تجاريًا مهمًا.

### 5. منطق الأعمال داخل Blade

ابحث عن:

- `Carbon::parse()` أو `now()` داخل Blade.
- `number_format()` متكرر بكثرة داخل Blade بدل قيم منسقة جاهزة.
- شروط مالية أو شفتات داخل Blade.
- بناء روابط ملفات أو تقارير من متغيرات غير مهيأة.
- JavaScript inline يستقبل عشرات المتغيرات من Blade.
- HTML ضخم ومتكرر لبطاقات أو modal أو جداول.

### 6. استخدام الجلسة داخل الكود

ابحث عن:

- `session()` داخل خدمات مالية أو منطق شفت.
- جلسة تغير مسار الحسابات أو تاريخ العمليات دون تغليف واضح.
- session keys منتشرة في أكثر من ملف.

#### القاعدة

إذا كانت الجلسة جزءًا من تدفق عمل مهم، يجب تغليفها في Service واضحة، مثل:

```php
AccountantShiftSessionService
ShiftGapSessionService
```

### 7. التكاملات الخارجية والملفات

ابحث عن:

- إنشاء PDF داخل transaction.
- إرسال WhatsApp داخل Controller.
- توكنات أو instance IDs داخل الكود بدل config/env.
- حفظ تقارير مالية داخل `public` بدون route محمي.
- أسماء ملفات مبنية من ملاحظات أو مدخلات مستخدم دون تنظيف.
- curl خام بدون timeout/retry.

---

## قائمة الإصلاحات المطلوبة

### 1. إصلاح المسؤوليات

- اجعل Controller منسقًا فقط.
- انقل منطق العمل إلى Services.
- انقل الاستعلامات المتكررة إلى Query Services أو Scopes.
- انقل تجهيز العرض إلى Presenters أو ViewModels.
- انقل PDF/WhatsApp إلى Jobs أو Services مستقلة.

### 2. إصلاح التكرار

- أنشئ خدمات مركزية للمنطق المتكرر.
- لا تكرر حساب التكلفة أو الصندوق أو حالة الشفت في أكثر من مكان.
- استبدل الشروط المتكررة بـ scopes مثل:

```php
Sale::forStore($storeId)
Sale::accountingPeriod($period)
Expense::accountingPeriod($period)
Withdrawal::accountingPeriod($period)
```

### 3. إصلاح الأداء

- استخدم pagination حقيقي عند عرض القوائم.
- استخدم eager loading للعلاقات التي ستعرض داخل حلقات.
- استخدم `select()` للحقول المطلوبة فقط.
- استخدم chunk/lazy للتقارير الكبيرة.
- حمّل بيانات modal الثقيلة عند الفتح عبر endpoint.
- ضع cache لبطاقات الإحصاء الثقيلة، مع مفاتيح تعتمد على `store_id`, `business_date`, `daily_balance_id`, أو `start_time`.

### 4. إصلاح منطق الأعمال داخل Blade

- جهز التواريخ والأرقام والتسميات في Presenter/DTO.
- استخرج البطاقات والمودالات والجداول إلى components.
- استبدل الشروط المعقدة بـ flags جاهزة.
- لا تستخدم `now()` داخل Blade؛ مرر الوقت من Controller/Service.
- لا تبنِ روابط ملفات حساسة داخل Blade مباشرة.

### 5. إصلاح الجلسة

- اجمع مفاتيح session في خدمة واحدة.
- اجعل الخدمة مسؤولة عن القراءة والكتابة والتنظيف.
- لا تجعل Controller أو Service مالية تعتمد على أسماء session keys مباشرة.
- أضف اختبارات لتدفق الجلسة إن كان يؤثر على الشفت أو التاريخ المحاسبي.

### 6. إصلاح التكاملات الخارجية

- انقل PDF وWhatsApp إلى Jobs.
- انقل التوكنات إلى `config/services.php` و`.env`.
- أضف timeout/retry عند الاتصال الخارجي.
- احفظ التقارير في storage أو route محمي عند احتوائها على بيانات مالية.
- لا تفشل الإغلاق المالي إذا فشل إرسال WhatsApp؛ سجل الفشل واسمح بإعادة الإرسال.

### 7. إصلاح الاختبارات

- أضف Unit tests للخدمات المالية وخدمات الشفت.
- أضف Feature tests للصفحات الكبيرة.
- أضف اختبارات لحالات:
  - شفت واحد.
  - شفتان.
  - يوم سابق ناقص.
  - يوم حالي لا يظهر كناقص.
  - متجر موقوف.
  - محاسب غير فعال.
  - بيانات قديمة بلا `business_date` أو `daily_balance_id`.

---

## هيكلية ملفات مقترحة لإعادة التنظيم

هذه الهيكلية قابلة للتطبيق تدريجيًا، ولا يلزم تنفيذها دفعة واحدة.

```text
app/
  Services/
    Stores/
      StoreAccessService.php
      StoreStatusService.php

    Shifts/
      ShiftLifecycleService.php
      ShiftGapService.php
      ShiftGapRequestService.php
      ShiftOperationBinderService.php
      ShiftSessionService.php

    Accounting/
      AccountingPeriod.php
      AccountingPeriodQueryService.php
      CashReconciliationService.php
      SalesCostService.php
      OperationActorService.php

    Reports/
      ShiftReportService.php
      StoreMonthlyReportService.php
      PdfReportService.php
      ReportStorageService.php

    Employees/
      EmployeeHistoricalStoreService.php
      EmployeePayrollService.php
      EmployeeOperationsSummaryService.php

    Notifications/
      WhatsAppNotifierInterface.php
      WhatsAppReportNotifier.php
      NotificationDispatchService.php

  ViewModels/
    Accountant/
      AccountantDashboardViewData.php
      ShiftSummaryViewData.php
      OperationRowViewData.php

    Store/
      StoreDashboardViewData.php
      StoreMonthlyReportViewData.php
      ShiftGapCardViewData.php

  QueryBuilders/
    SalesQuery.php
    ExpensesQuery.php
    WithdrawalsQuery.php
    EmployeeOperationsQuery.php

  Jobs/
    GenerateShiftReportPdf.php
    SendShiftReportWhatsApp.php
    CleanupOldReports.php

resources/
  views/
    components/
      dashboard/
        stat-card.blade.php
        operations-modal.blade.php
        operation-row.blade.php
        balance-summary.blade.php

      shifts/
        shift-card.blade.php
        shift-gap-request-card.blade.php

      reports/
        report-actions.blade.php
        report-summary.blade.php
```

---

## طريقة العمل عند فتح فرع أو مهمة جديدة

اتبع الخطوات التالية في أي فرع جديد:

1. حدد الملف أو الشاشة المراد إصلاحها.
2. صنّف المشاكل باستخدام قائمة العيوب أعلاه.
3. حدد هل المشكلة:
   - مسؤولية زائدة.
   - تكرار استعلام.
   - منطق أعمال داخل Controller.
   - منطق عرض داخل Blade.
   - مشكلة session.
   - مشكلة أداء.
4. اختر Service أو Component مناسب من الهيكلية المقترحة.
5. استخرج أصغر جزء آمن أولًا.
6. أضف اختبارًا أو فحصًا مناسبًا.
7. لا تغير الحسابات المالية أو ربط الشفتات إلا مع مقارنة قبل/بعد.
8. احتفظ بدعم البيانات القديمة عبر fallback واضح.
9. لا تنقل كل شيء دفعة واحدة؛ كل PR يجب أن يكون له هدف محدد.

---

## معيار قبول أي refactor

أي طلب سحب لإعادة التهيئة يعتبر مقبولًا إذا حقق الشروط التالية:

- قلل مسؤولية Controller أو Blade.
- لم يغير نتيجة مالية بدون توثيق واختبار.
- أزال تكرارًا واضحًا أو نقله إلى خدمة مركزية.
- حسّن قابلية الاختبار.
- لم يكسر دعم البيانات القديمة.
- أضاف أو حدّث اختبارًا أو على الأقل فحصًا واضحًا إذا كانت بيئة الاختبار غير جاهزة.
- حافظ على واجهة المستخدم أو حسّنها دون تغيير مفاجئ في السلوك.

---

## قالب نتيجة مراجعة أي ملف

عند مراجعة أي ملف في مهمة جديدة، اكتب النتيجة بهذا الشكل:

```md
## الملف
path/to/file.php

## قائمة العيوب/المشاكل
- ...

## الإصلاحات المطلوبة
- ...

## الخدمات أو المكونات المقترحة
- ServiceName
- ComponentName

## مخاطر التعديل
- ...

## خطة تنفيذ آمنة
1. ...
2. ...
3. ...

## اختبارات مطلوبة
- ...
```

هذا القالب يجعل المستند صالحًا للعمل حتى لو تغير الفرع أو تم فتح طلب سحب جديد أو بدأت مهمة منفصلة.

---

## قواعد التوثيق أثناء التطبيق

هذه القواعد إلزامية عند تنفيذ أي خطوة refactor لاحقة:

- عند تطبيق أي خدمة أو نقل أي منطق من Controller أو Blade يجب توثيق ذلك في هذا المستند أو في مستند المجال المناسب.
- يجب إضافة قسم يوضح: ما الذي تم نقله، من أين نُقل، إلى أي خدمة أو مكون نُقل، وما السلوك الذي يجب ألا يتغير.
- أي دالة قديمة سيتم حذفها أو إبقاؤها مؤقتًا يجب ذكرها صراحة في قسم **الدوال القديمة المرشحة للحذف**.
- إذا بقيت دالة قديمة كواجهة توافقية، يجب توضيح أنها مؤقتة وأن الاستدعاءات الجديدة لا تستخدمها.
- لا يتم حذف دالة قديمة تؤثر على التقارير أو الشفتات أو البيانات المالية إلا بعد التأكد من عدم وجود استدعاءات لها وإضافة اختبار أو فحص واضح.

### الدوال القديمة المرشحة للحذف أو التحويل

عند كل refactor أضف صفًا جديدًا هنا:

| الدالة/الملف القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| `StoreController::authorizeStoreAccess` | باقية مؤقتًا كواجهة داخلية | `StoreAccessService::ensureOwnerCanAccess` | تبقى لتقليل حجم التغيير؛ أي تحقق جديد يجب أن يمر عبر الخدمة |
| شروط `store_id/user_id/status` داخل تدفقات المتجر | بدأ تحويلها | `StoreAccessService` | تم البدء بتدفقات مراجعة الشفت وتعيين المتجر الحالي ولوحة المالك |

---

## قواعد التسمية والتعليقات

هذه القواعد مثبتة لتجنب المتغيرات المبهمة أثناء refactor:

- يمنع استخدام أسماء مبهمة مثل `$data`, `$result`, `$item`, `$row`, `$temp` في منطق مالي أو شفتات إلا إذا كان النطاق صغيرًا وواضحًا جدًا.
- يجب أن يصف اسم المتغير وظيفته، مثل:
  - `$activeOwnerStores` بدل `$stores` عند الحاجة للتمييز.
  - `$shiftGapRequestStatus` بدل `$status`.
  - `$businessDateOperationCounts` بدل `$counts`.
  - `$expectedCashInSafe` بدل `$expected`.
- أسماء الخدمات يجب أن تعكس مسؤوليتها بوضوح، مثل `StoreAccessService`, `SalesCostService`, `ShiftOperationBinderService`.
- أسماء الدوال يجب أن تبدأ بفعل واضح: `ensure`, `build`, `calculate`, `attach`, `resolve`, `format`, `present`.
- كل Service جديدة يجب أن تحتوي تعليقات توضيحية قصيرة على الدوال العامة تشرح وظيفتها وحدودها.
- كل دالة تنقل من Controller إلى Service يجب أن تحتوي تعليقًا يوضح لماذا توجد ومتى تستخدم.
- إذا كان المتغير يمثل قيمة مالية أو تاريخًا محاسبيًا أو رقم شفت، يجب أن يكون الاسم صريحًا ولا يعتمد على السياق فقط.
- التعليقات يجب أن تشرح سبب القرار أو حدود الدالة، لا أن تكرر اسم الدالة فقط.

---

## سجل التطبيق الفعلي للخطة

### 1. بدء استخراج منطق وصول المتجر

- تم إنشاء `App\Services\Stores\StoreAccessService` كأول خدمة مشتركة منخفضة المخاطر.
- الخدمة أصبحت مسؤولة عن:
  - جلب المتاجر النشطة للمالك.
  - التحقق من أن المالك يملك المتجر.
  - تحديد هل المتجر نشط.
  - تحديد هل المتجر صالح لتدفقات الشفتات.
- تم توجيه `StoreController::authorizeStoreAccess` إلى الخدمة بدل احتواء شرط الملكية داخله مباشرة.
- تم استخدام الخدمة في تدفقات:
  - طلب مراجعة شفت من محاسب.
  - الإغلاق من صفحة مراجعة الشفتات.
  - نقل تاريخ الشفت.
  - تعيين المتجر الحالي.
  - تحميل متاجر لوحة المالك النشطة.

#### ملاحظة توافق

لم يتم حذف `StoreController::authorizeStoreAccess` الآن، بل بقي كدالة داخلية تفوض إلى `StoreAccessService` لتقليل حجم التغيير وحماية الاستدعاءات القديمة داخل الكنترولر. الهدف النهائي لاحقًا هو تقليل الاعتماد على دوال التحقق الخاصة داخل الكنترولرات واستخدام الخدمات أو Policies مباشرة.

### 2. بدء استخراج منطق تكلفة المبيعات

- تم إنشاء `App\Services\Accounting\SalesCostService` كخدمة مركزية أولى لحساب تكلفة المنتجات المباعة خلال فترة.
- تم نقل منطق fallback الخاص بالعمليات القديمة من `StoreController::calculateSoldProductsCostForPeriod` إلى الخدمة الجديدة.
- تم حذف `StoreController::calculateSoldProductsCostForPeriod` لاحقًا بعد تحويل الاستدعاء المتبقي إلى `SalesCostService` مباشرة.
- الهدف من هذه الخطوة أن تصبح كل التقارير لاحقًا معتمدة على مصدر تكلفة مركزي بدل تكرار معادلات التكلفة داخل أكثر من Controller أو Report.

| الدالة/الملف القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| `StoreController::calculateSoldProductsCostForPeriod` | حذفت | `SalesCostService::soldProductsCostForPeriod` | تم تحويل الاستدعاء المتبقي إلى الخدمة مباشرة |

---

## توضيح الدوال التوافقية وخطة حذفها

عند إنشاء دالة توافقية مؤقتة، يجب أن تحتوي داخل الكود على تعليق يوضح:

1. لماذا بقيت الدالة.
2. ما الدالة أو الخدمة الأساسية التي يجب استخدامها بدلًا منها.
3. متى يمكن حذفها.
4. ما الاختبار أو الفحص المطلوب قبل الحذف.

### القاعدة المعتمدة

- أي دالة توافقية يجب أن تبدأ تعليقها بعبارة واضحة مثل: `واجهة توافقية مؤقتة`.
- يجب أن يحتوي التعليق على `مكانها الأساسي الآن` و`خطة الحذف`.
- عند التخلص من الحسابات والبيانات القديمة في بداية شهر 7، تتم مراجعة كل الدوال التوافقية المتعلقة بالتكلفة وfallback القديم.
- لا تحذف أي دالة توافقية قبل البحث عن كل استدعاءاتها وتشغيل اختبار أو فحص يحمي النتائج المالية والتقارير.

### تحديث حالة الدوال التوافقية الحالية

| الدالة التوافقية | مكانها الأساسي الآن | خطة الحذف | فحص قبل الحذف |
|---|---|---|---|
| `StoreController::calculateSoldProductsCostForPeriod` | `SalesCostService::soldProductsCostForPeriod` | حذفت بعد تحويل الاستدعاء المتبقي إلى الخدمة مباشرة | مقارنة تكلفة التقرير الشهري قبل/بعد وتشغيل اختبار التقرير الشهري |
| `StoreController::authorizeStoreAccess` | `StoreAccessService::ensureOwnerCanAccess` أو Policy لاحقًا | تحذف بعد نقل صلاحيات المتاجر إلى خدمة/Policy في كل الكنترولرات | فحص كل routes التي تستخدم StoreController والتأكد من منع الوصول لمتجر غير مملوك |

### ملاحظة حول حسابات التكلفة القديمة

تم وضع تعليقات داخل `SalesCostService` على فروع fallback القديمة لتوضيح أنها مؤقتة. بعد بداية شهر 7، وعند اعتماد `sale_items.total_cost` كمصدر نهائي، يجب تحويل fallback إلى أداة ترحيل/تدقيق بيانات أو حذفه من مسار الحساب اليومي بعد اختبار التقارير.

### 3. بدء استخراج منطق المحاسبين النشطين

- تم إنشاء `App\Services\Stores\ActiveAccountantService` لتجميع قواعد جلب المحاسبين النشطين المرتبطين بمتجر ومالك محددين.
- تم نقل شرط `store_id + user_id + status = active` من لوحة المالك وطلب مراجعة الشفت إلى الخدمة الجديدة.
- الهدف من هذه الخطوة منع تكرار شروط المحاسب الفعال بين Controller وBlade وتجهيز الطريق لاحقًا لواجهة عدم وجود محاسبين فعالين وإعادة تعيين الطلب وإشعار المحاسب المختار.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| استعلامات المحاسب النشط داخل `StoreController` و`UserDashboardController` | بدأ تحويلها | `ActiveAccountantService` | تنقل أي استعلامات محاسب نشط جديدة إلى الخدمة مباشرة |

### 4. بدء استخراج معلومات الشفت الناقص

- تم إنشاء `App\Services\Shifts\ShiftGapInfoService` لتجميع حساب عدد الشفتات المغلقة، رقم الشفت الناقص، ووسم الشفت المعروض.
- تم نقل منطق `shiftGapShiftInfo` المكرر من لوحة المحاسب ولوحة المالك وصفحة مراجعة الشفتات إلى الخدمة الجديدة.
- تم نقل بناء صف تنبيه الشفت الناقص في لوحة المالك إلى `ShiftGapInfoService::missingShiftRowsForDate`.
- الهدف من هذه الخطوة منع اختلاف تنبيهات الشفت بين لوحة المالك وصفحة مراجعة الشفتات ولوحة المحاسب.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| `shiftGapShiftInfo` داخل الكنترولرات | تم نقلها | `ShiftGapInfoService::shiftInfo` | أي حساب جديد لمعلومات الشفت الناقص يجب أن يستخدم الخدمة مباشرة |
| `UserDashboardController::shiftGapRowsForDate` | تم نقلها | `ShiftGapInfoService::missingShiftRowsForDate` | يحافظ على قاعدة عدم عرض الشفت الثاني قبل اكتمال الأول |

### 5. بدء استخراج حالات طلبات الشفت الناقص

- تم إنشاء `App\Services\Shifts\ShiftGapRequestService` لتجميع قراءة حالات طلبات مراجعة الشفت النشطة من السجلات.
- تم نقل منطق البحث عن حالة الطلب النشط من `StoreController` إلى `ShiftGapRequestService::activeStatus`.
- تم نقل بناء خريطة حالات طلبات الشفتات الناقصة من `UserDashboardController` إلى `ShiftGapRequestService::activeStatusesForMissingRows`.
- الهدف من هذه الخطوة أن تصبح حالة الطلب `pending / in_progress` موحدة في لوحة المالك وصفحة مراجعة الشفتات، تمهيدًا لاحقًا لنقل إنشاء/إلغاء/إعادة تعيين الطلبات إلى نفس الخدمة.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| `StoreController::shiftGapRequestStatus` | تم نقلها | `ShiftGapRequestService::activeStatus` | أي قراءة جديدة لحالة طلب شفت يجب أن تستخدم الخدمة مباشرة |
| `UserDashboardController::shiftGapRequestStatuses` | تم نقلها | `ShiftGapRequestService::activeStatusesForMissingRows` | يحافظ على مفتاح الحالة business_date#shift_number |

### 6. بدء نقل إنشاء طلب مراجعة الشفت الناقص وإشعار المحاسب

- تم توسيع `App\Services\Shifts\ShiftGapRequestService` بدالة `createOwnerRequest` لتكون نقطة الإنشاء الأساسية لطلب مراجعة الشفت الناقص من المالك.
- تم نقل إنشاء سجل `shift_gap_accountant_request` وإشعار المحاسب المختار من `StoreController::requestAccountantShiftInput` إلى الخدمة الجديدة.
- بقي `StoreController::requestAccountantShiftInput` مسؤولًا عن التحقق من المدخلات والصلاحية وحالة التاريخ فقط، ثم يفوض إنشاء الطلب للخدمة.
- الهدف من هذه الخطوة أن تصبح قواعد إنشاء الطلب، مفتاح الشفت `business_date#shift_number`، ورسالة إشعار المحاسب في مكان واحد قبل نقل الإلغاء وإعادة التعيين لاحقًا.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| إنشاء `Log` و`Notification` داخل `StoreController::requestAccountantShiftInput` | تم نقله | `ShiftGapRequestService::createOwnerRequest` | أي إنشاء جديد لطلب شفت ناقص يجب أن يستخدم الخدمة حتى لا تختلف بيانات السجل أو الإشعار |

#### ملاحظة تنفيذية

هذه الخطوة لا تلغي مسار الطلب الحالي ولا تغير أسماء الحقول المخزنة داخل `details`، لكنها تقلل تضخم الكنترولر وتجهز نقل الإلغاء/إعادة التعيين إلى خدمة واحدة. عند تنفيذ إعادة تعيين الطلب لمحاسب آخر، يجب ألا يُعاد بناء الإشعار داخل الكنترولر، بل تضاف دالة واضحة داخل `ShiftGapRequestService` باسم وظيفي مثل `reassignOwnerRequest`.

### 7. نقل إلغاء طلب مراجعة الشفت الناقص إلى الخدمة

- تم إضافة `ShiftGapRequestService::cancelOwnerRequest` لإلغاء الطلب النشط لنفس المتجر والتاريخ ورقم الشفت.
- تم إعادة استخدام دالة داخلية واحدة داخل الخدمة باسم `activeRequestLog` حتى لا يتكرر منطق البحث عن الطلب النشط بين قراءة الحالة والإلغاء.
- تم إعادة إضافة/تثبيت مسار `StoreController::cancelAccountantShiftInputRequest` كدالة خفيفة: تتحقق من الصلاحية والمدخلات ثم تفوض الإلغاء للخدمة.
- عند الإلغاء يتم تحديث سجل الطلب الأصلي إلى `status = canceled` مع `canceled_at` و`canceled_by`، ثم إنشاء سجل تدقيق منفصل `shift_gap_accountant_request_canceled`.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| منطق إلغاء طلب المحاسب داخل الكنترولر | تم تثبيته كاستدعاء خدمة | `ShiftGapRequestService::cancelOwnerRequest` | أي إلغاء أو إعادة تعيين لاحق يجب أن يبدأ من الخدمة حتى يبقى أثر التدقيق موحدًا |
| تكرار البحث عن آخر طلب نشط | تم توحيده | `ShiftGapRequestService::activeRequestLog` | دالة داخلية خاصة بالخدمة، ولا تستدعى من الكنترولرات |

#### ملاحظة للخطوة التالية

بعد هذه الخطوة، أصبح إنشاء الطلب وإلغاؤه داخل نفس الخدمة. الخطوة الطبيعية التالية هي إضافة دالة `reassignOwnerRequest` بدل جعل إعادة التعيين عملية إلغاء ثم إنشاء يدويين داخل الواجهة، مع الحفاظ على نفس مفاتيح `business_date#shift_number` ونفس سجل التدقيق.

### 8. إضافة إعادة تعيين طلب الشفت لمحاسب آخر

- تم إضافة `ShiftGapRequestService::reassignOwnerRequest` كدالة أساسية لإعادة تعيين طلب شفت ناقص نشط إلى محاسب آخر.
- تحفظ الدالة المحاسب السابق في `previous_accountant_id` و`previous_accountant_name`، وتحدّث المحاسب الجديد، وتضيف `reassigned_at` و`reassigned_by` داخل تفاصيل الطلب الأصلي.
- يتم إنشاء سجل تدقيق مستقل باسم `shift_gap_accountant_request_reassigned` مع إرسال إشعار للمحاسب الجديد عبر نفس الخدمة.
- تم إضافة endpoint خفيف في `StoreController::reassignAccountantShiftInputRequest` ومسار مستقل `shift-gaps.request-accountant.reassign`، بحيث لا تحتاج الواجهة إلى إلغاء الطلب ثم إعادة إنشائه يدويًا.
- تم تعديل صفحة مراجعة الشفتات لإظهار زر إلغاء مستقل وزر إعادة تعيين مستقل عند وجود أكثر من محاسب فعال.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| إعادة التعيين اليدوية عبر إلغاء ثم إنشاء طلب جديد | استبدلت بمسار مباشر | `ShiftGapRequestService::reassignOwnerRequest` | يحافظ على سجل الطلب الأصلي ويضيف أثر تدقيق مستقل للإعادة |
| بناء إشعار المحاسب المختار داخل الواجهة/الكنترولر | ممنوع في الخطوات القادمة | `ShiftGapRequestService` | أي تغيير في نص الإشعار أو بياناته يجب أن يتم داخل الخدمة |

#### أثر هذه الخطوة على مبدأ المسؤولية الواحدة

الواجهة أصبحت تعرض القرار فقط، والكنترولر يتحقق ويفوض، والخدمة تحفظ الطلب وتديره وتوثق الأثر وترسل الإشعار. هذا يجعل إعادة التعيين قابلة للاختبار لاحقًا دون الحاجة لاختبار Blade أو مسار HTTP كامل في كل مرة.

### 9. تقليل StoreController فعليًا باستخراج تجهيز صفحة مراجعة الشفتات

- تم إنشاء `App\Services\Shifts\ShiftGapOverviewService` لتجهيز بيانات صفحة مراجعة الشفتات بدل بنائها داخل `StoreController`.
- تم نقل تجميع `gapRows`، وجلب آخر الإقفالات، وجلب المحاسبين الفعالين، وحساب أعداد عمليات اليوم الناقص إلى الخدمة الجديدة.
- أصبح `StoreController::shiftGaps` دالة قصيرة: تتحقق من الصلاحية ثم تطلب بيانات الصفحة من الخدمة وتعيد الـ view.
- تم حذف دالة `StoreController::shiftGapOperationCounts` من الكنترولر، واستبدالها بـ `ShiftGapOverviewService::operationCounts` حتى يستخدمها الإغلاق الصفري أيضًا.
- هذه الخطوة خفضت أسطر `StoreController` بوضوح بدل الاكتفاء بإضافة خدمات جديدة دون إزالة كتلة كبيرة من الكنترولر.

| الدالة/المنطق القديم | الحالة | البديل الجديد | ملاحظات الحذف الآمن |
|---|---|---|---|
| `StoreController::shiftGaps` كدالة تجمع بيانات الصفحة بنفسها | تم اختصارها | `ShiftGapOverviewService::ownerOverview` | أي تعديل في بيانات صفحة مراجعة الشفتات يجب أن يبدأ من الخدمة |
| `StoreController::shiftGapOperationCounts` | حذفت من الكنترولر | `ShiftGapOverviewService::operationCounts` | يستخدمها عرض الصفحة والإغلاق الصفري بنفس القاعدة |

#### قاعدة متابعة مهمة

أي خطوة Refactor قادمة يجب أن تحذف كتلة فعلية من الكنترولر أو الـ Blade، لا أن تضيف خدمة فقط. معيار القبول العملي: نقل responsibility كاملة مثل صفحة، تقرير، أو عملية إغلاق إلى Service/Action ثم إبقاء الكنترولر كطبقة HTTP رقيقة.

### 10. حذف كتل توافقية لم تعد مستخدمة داخل StoreController

- تم حذف `StoreController::calculateSoldProductsCostForPeriod` لأنها لم تعد تضيف أي منطق؛ كانت فقط تمرر الاستدعاء إلى `SalesCostService`.
- تم تحويل استدعاء التقرير الشهري مباشرة إلى `SalesCostService::soldProductsCostForPeriod`.
- تم حذف import غير مستخدم لـ `Storage` من `StoreController`.
- هذه الخطوة تطبق قاعدة: إذا بقيت دالة توافقية بلا استدعاءات خارجية ولا تضيف منطقًا، تُحذف بدل تركها كتلة ميتة داخل الكنترولر.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| `StoreController::calculateSoldProductsCostForPeriod` | حذفت | `SalesCostService::soldProductsCostForPeriod` |
| `use Illuminate\Support\Facades\Storage` | حذف import غير مستخدم | لا يوجد بديل لأنه غير مستخدم |

### 11. استخراج كتلة التقرير الشهري من StoreController

- تم إنشاء `App\Services\Reports\MonthlyStoreReportService` ونقل كتلة التقرير الشهري إليها بالكامل تقريبًا.
- تم نقل بناء عنوان التقرير، اسم ملف PDF الآمن، بيانات التقرير المختصر، بيانات التقرير المفصل، ملخص النقل، صفوف المبيعات اليومية، صفوف المشتريات، صفوف استهلاك المحاسب، المصروفات، الموظفين، وحساب الرواتب الشهرية التناسبية إلى الخدمة.
- أصبحت دوال `reportsMonthly` و`reportsMonthlyPdf` داخل `StoreController` مسؤولة فقط عن قراءة مدخلات HTTP، تفويض بناء البيانات للخدمة، وإرجاع view/PDF.
- تم حذف imports لم تعد مستخدمة في `StoreController` بعد النقل مثل `StoreTransfer`, `SalesCostService`, و`EmployeeService`.
- هذه خطوة تخفيض كبيرة لعدد أسطر الكنترولر، لأنها تنقل responsibility كاملة وهي تقرير شهري كامل، وليس helper صغير فقط.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| دوال بناء التقرير الشهري داخل `StoreController` | نقلت | `MonthlyStoreReportService` |
| `buildMonthlyReportData` | نقلت | `MonthlyStoreReportService::buildMonthlyReportData` |
| `buildMonthlyReportTitle` | نقلت | `MonthlyStoreReportService::buildMonthlyReportTitle` |
| `buildSafeReportFileName` | نقلت | `MonthlyStoreReportService::buildSafeReportFileName` |
| دوال monthly* الخاصة بصفوف التقرير | نقلت كدوال خاصة داخل الخدمة | `MonthlyStoreReportService` |

### 12. استخراج تقرير البحث الشامل من StoreController

- تم إنشاء `App\Services\Reports\ComprehensiveStoreSearchReportService` ونقل منطق تقرير البحث الشامل إليه.
- تم نقل بناء استعلامات المبيعات، الاستهلاك الداخلي، مشتريات المالك، ملخصات النتائج، وتجميع العمليات الموحدة إلى الخدمة.
- أصبحت `StoreController::reportsComprehensiveSearch` تتحقق من الصلاحية والمدخلات فقط، ثم تفوض بناء التقرير للخدمة وتعيد الـ view.
- هذه الخطوة تزيل كتلة كبيرة أخرى من الكنترولر وتضع قواعد البحث والتجميع في مكان قابل للاختبار والتحسين لاحقًا.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| منطق `reportsComprehensiveSearch` التفصيلي داخل `StoreController` | نقل | `ComprehensiveStoreSearchReportService::build` |
| تجميع `unifiedOperations` داخل الكنترولر | نقل | `ComprehensiveStoreSearchReportService` |
| ملخصات sales/internal/purchases داخل الكنترولر | نقل | `ComprehensiveStoreSearchReportService` |

### 13. استخراج صفحة تفاصيل المتجر من StoreController

- تم إنشاء `App\Services\Stores\StoreDetailsService` ونقل إحصاءات صفحة تفاصيل المتجر إليها.
- تم نقل حسابات المخزون، الموظفين، الديون، المبيعات، المصروفات، الربحية، الموازنات، والمنتجات النشطة إلى الخدمة.
- أصبحت `StoreController::details` تجلب المتجر المملوك للمستخدم ثم تفوض بناء البيانات للخدمة وتعيد الـ view.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| منطق `details` داخل `StoreController` | نقل | `StoreDetailsService::build` |
| إحصاءات المخزون والموظفين والمبيعات داخل الكنترولر | نقل | `StoreDetailsService` |

### 14. استخراج قراءة ملفات تقارير آخر 10 أيام

- تم إنشاء `App\Services\Reports\RecentReportFilesService` لتجميع قراءة ملفات PDF الأخيرة للمتجر.
- تم نقل التعامل مع مسارات ملفات التقارير، `glob`, `filemtime`, `filesize`, وتاريخ القطع من `StoreController::reportsLastTenDays` إلى الخدمة.
- أصبحت دالة `reportsLastTenDays` مسؤولة عن الصلاحية فقط ثم تفويض قراءة الملفات للخدمة وإرجاع الـ view.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| قراءة ملفات reports داخل `StoreController` | نقل | `RecentReportFilesService::recentForStore` |
| حساب `cutoffDate` وحجم الملفات داخل الكنترولر | نقل | `RecentReportFilesService` |

### 15. توحيد تسميات طرق الدفع وتنظيف قالب PDF القديم للفواتير

- تم إنشاء `App\Support\PaymentTypeLabel` كمصدر مشترك لتسميات طرق الدفع في الكنترولرات وملفات Blade.
- تم نقل تكرار `match` الخاص بـ `cash/card/mixed/credit/internal_use` من عرض الفواتير ولوحة المحاسب إلى helper واحد.
- أصبحت صفحة إنشاء الفاتورة اليدوية تستخدم `PaymentTypeLabel::invoiceOptions()` حتى تكون خيارات الإدخال والتسميات المعروضة من نفس المصدر.
- تم حذف القالب القديم `resources/views/pdf/invoice-pdf.blade.php` لأنه لم يعد مستخدمًا بعد جعل تصدير PDF يعتمد على نفس قوالب الطباعة (`invoices.print` و`cashier.quick-sale.invoice-print`) عبر `InvoiceController::downloadPDF`.
- سبب الحذف: القالب القديم كان يكرر تصميمًا منفصلًا تسبب بدمج أعمدة/خانات عند التصدير، ولا توجد له أي ارتباطات حالية بعد البحث عنه.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| تكرار `match` لطريقة الدفع في Blade/Controller | توحيد | `PaymentTypeLabel::invoiceLabel`, `PaymentTypeLabel::dashboardLabel` |
| خيارات الدفع المكتوبة يدويًا في نموذج إنشاء الفاتورة | توحيد | `PaymentTypeLabel::invoiceOptions` |
| `resources/views/pdf/invoice-pdf.blade.php` | حذف | استخدام قوالب الطباعة نفسها للتصدير PDF |

### 16. خطوتان لتوسيع الخدمات المشتركة خارج الفواتير والمتجر

#### الخطوة الأولى: توسيع `PaymentTypeLabel` بدل تكرار تسميات الدفع

- لم تعد خدمة `PaymentTypeLabel` محصورة في الفواتير فقط؛ أصبحت مستخدمة كذلك في البيع السريع، المبيعات اليومية، تقرير شفت المحاسب، وتقرير PDF العام.
- تم نقل تسميات رسائل البيع السريع، تسميات صفحة المبيعات اليومية، وشارات تقارير PDF إلى نفس المصدر حتى لا تتغير عبارة `cash/card/mixed/credit` في مكان وتبقى قديمة في مكان آخر.
- تم حذف كتل `match`/arrays المتكررة من الكنترولرات والقوالب التي أصبحت تعتمد على الخدمة.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| `QuickSaleController::getSaleTypeName` بمصفوفة داخلية | تفويض | `PaymentTypeLabel::quickSaleMessageLabel` |
| `DailySalesController` match لعرض طريقة الدفع | تفويض | `PaymentTypeLabel::dailySalesLabel` |
| شارة طريقة الدفع في `pdf_report.blade.php` | تفويض | `PaymentTypeLabel::reportBadge` |
| `typeMap` داخل تقرير شفت المحاسب | تفويض | `PaymentTypeLabel::reportBadge` |

#### الخطوة الثانية: استخراج بحث المنتجات المشترك

- تم إنشاء `App\Services\Products\ProductSearchService` لأن البحث عن المنتجات كان مكررًا في البيع السريع، الاستهلاك الداخلي، صفحة منتجات المالك، وصفحات منتجات المحاسب.
- الخدمة توحد:
  - البحث بالاسم والوصف والباركود.
  - تطبيع الأحرف العربية الشائعة (`أ/إ/آ`، `ى/ئ`، `ة`...).
  - ترتيب النتائج حسب صلة البحث ثم حالة المخزون.
  - تجهيز حقول البيع السريع الخاصة بالكسور/الرولات/الأطقم.
- تم حذف منطق البحث المباشر من الكنترولرات واستبداله باستدعاءات خدمة واحدة، مع إبقاء الفلاتر الخاصة بكل صفحة في مكانها لأنها ليست مشتركة بالكامل.

| الكتلة القديمة | القرار | البديل الحالي |
|---|---|---|
| بحث البيع السريع داخل `ProductSearchController` | نقل | `ProductSearchService::quickSaleResults` |
| بحث الاستهلاك الداخلي داخل `InternalUseController` | نقل | `ProductSearchService::pickerResults` |
| بحث صفحة منتجات المالك داخل `ProductController::index` | تفويض | `ProductSearchService::applySearch` |
| بحث منتجات المحاسب/نقطة البيع داخل `ProductController` | تفويض | `ProductSearchService::applySearch` |

#### قاعدة متابعة

أي صفحة جديدة تحتاج بحث منتجات يجب ألا تكتب `where name LIKE ...` داخل الكنترولر مباشرة. تستخدم `ProductSearchService`، وإذا احتاجت شكل نتائج مختلف نضيف method صغيرًا داخل الخدمة بدل تكرار شروط البحث في الصفحة.

### 17. تصحيح خدمة بحث المنتجات وتنظيف ما تبقى بعد النقل

#### الخطوة الأولى: فصل شروط البحث عن طريقة العرض

- تم تعديل `ProductSearchService` حتى لا تفرض طريقة عرض واحدة على كل الصفحات.
- البيع السريع بقي له مساره الخاص `quickSaleResults` لأنه يحتاج علاقات الكسور، ترتيب الصلة/الأكثر بيعاً، وتجهيز حقول الرول/المتر/الطقم.
- صفحة منتجات المالك أصبحت تستخدم `applyOwnerCatalogSearch` فقط للبحث، بينما تبقى فلاتر القسم/الحالة وترتيب المخزون داخل الصفحة كما كانت.
- صفحات منتجات المحاسب/نقطة البيع أصبحت تستخدم `applyAccountantCatalogSearch` فقط للبحث، بدون فرض تجهيزات البيع السريع أو نفس عدد النتائج.
- بحث الاستهلاك الداخلي بقي مختصراً عبر `pickerResults` لأنه منتقي منتجات وليس صفحة عرض كاملة.

| الشاشة | method المستخدم | ما الذي لا تفرضه الخدمة عليها |
|---|---|---|
| البيع السريع | `quickSaleResults` | لا يشارك تجهيز العرض مع صفحات المنتجات العادية |
| منتجات المالك | `applyOwnerCatalogSearch` | لا يغير pagination أو ترتيب الصفحة |
| منتجات المحاسب/نقطة البيع | `applyAccountantCatalogSearch` | لا يفرض حد نتائج البيع السريع أو تنسيقاته |
| الاستهلاك الداخلي | `pickerResults` | لا يرجع كل بيانات صفحة المنتجات الكاملة |

#### الخطوة الثانية: تنظيف وإصلاح الربط بعد التوحيد

- تم إصلاح import الناقص في `DailySalesController` حتى يشير إلى `App\Support\PaymentTypeLabel` بدلاً من محاولة البحث عنها داخل namespace الكنترولر.
- تم تحويل دالة تطبيع البحث العربي داخل `ProductSearchService` إلى private لأنها لم تعد API عامة.
- تم حذف بقايا التنسيق الزائد من `ProductSearchController` بعد نقل كتلة البحث الطويلة للخدمة.
- معيار التنظيف هنا: أي دالة أو كتلة كانت فقط تخدم البحث القديم داخل الكنترولر ولم تعد تضيف سلوكاً مستقلاً، إما حذفت أو أصبحت private داخل الخدمة المشتركة.

### 18. تصحيح تاريخ الشفت الحالي في لوحة المحاسب

#### الخطوة الأولى: منع رجوع الشفت الحالي لتاريخ قديم بدون طلب مفعل

- تم تعديل `ShiftLifecycleService::currentShiftContext` حتى يكون الشفت الحالي الطبيعي على تاريخ التشغيل الحالي.
- إذا كان آخر إقفال قديمًا، وكان النظام يستطيع حساب شفت ثانٍ لذلك التاريخ القديم، لا يتم استخدام ذلك التاريخ للشفت الحالي.
- التاريخ المرتجع لا يستخدم إلا في حالة واحدة واضحة: عند تفعيل طلب شفت ناقص من المحاسب عبر session `accountant_shift_gap_business_date`.
- بهذا لا تظهر رسالة مثل: "الشفت الحالي محسوب كشفت رقم 2 لتاريخ قديم" في يوم لاحق، إلا إذا كان المحاسب فعّل طلب ذلك التاريخ صراحة.

#### الخطوة الثانية: إبقاء مسار الشفتات الناقصة منفصلًا عن الشفت الحالي

- بقيت دالة `activeAccountantGapDate` هي المسار الوحيد الذي يسمح بتسجيل عمليات على تاريخ ناقص سابق.
- بقيت `missingBusinessDates` مسؤولة عن عرض الأيام الناقصة كتنبيهات/طلبات مراجعة، لكن لا تغيّر تاريخ الشفت الحالي تلقائيًا.
- معيار المتابعة: أي إدخال على تاريخ سابق يجب أن يمر عبر تفعيل طلب شفت ناقص، وليس عبر حساب الشفت الحالي العادي.

### 19. خطوتان لتوحيد نطاق الشفت المفتوح في لوحة المحاسب

#### الخطوة الأولى: استخراج شرط الشفت المفتوح إلى Scope مشترك

- تم توسيع `HasAccountingDateScopes` بإضافة `forOpenAccountingShift` ليجمع شرطين كانا يتكرران في لوحة المحاسب:
  - عند وجود `business_date`: اختيار عمليات ذلك التاريخ غير المرتبطة بموازنة مغلقة (`daily_balance_id IS NULL`).
  - عند عدم وجود `business_date`: الرجوع إلى العمليات بعد بداية الشفت (`created_at > shiftStart`).
- هذا النطاق يخدم `Sale`, `Expense`, و`Withdrawal` لأنها كلها تستخدم نفس Trait.

#### الخطوة الثانية: تنظيف تكرار الاستعلامات في DashboardController

- تم استبدال كتل `when($businessDate, whereDate(...)->whereNull(...), where(created_at...))` داخل لوحة المحاسب باستدعاء `forOpenAccountingShift`.
- تم تطبيق ذلك على إحصاءات المبيعات، المؤشرات السريعة، العمليات الأخيرة، المصروفات، السحوبات، وعدّ المديونيات المعلقة.
- الهدف من هذه الخطوة ليس تغيير العرض، بل حذف شرط مكرر حساس حتى لا يتم تعديله في مكان ونسيانه في مكان آخر.

### 20. خطوة كبيرة: ضبط تاريخ تطبيق نظام الشفتين وإشعارات طلبات الشفت الناقص

#### المشكلة الأولى: ظهور طلب الشفت الثاني لأيام قبل تحويل المتجر إلى شفتين

- تم تسجيل تغيير عدد شفتات المتجر في `Log` عند تعديل إعدادات المتجر (`store_shift_settings_changed`) مع حفظ العدد القديم والجديد وتاريخ التعديل.
- أصبح `ShiftLifecycleService::requiredShiftsForBusinessDate` يرجع إلى تاريخ تغيير إعداد الشفتات قبل أن يطلب شفتًا ثانيًا ليوم قديم.
- إذا كان التاريخ المحاسبي أقدم من تاريخ تحويل المتجر إلى شفتين، وكان لديه شفت مغلق واحد أو أقل، يبقى المطلوب لذلك التاريخ شفتًا واحدًا فقط.
- الهدف: لا تظهر مراجعة “الشفت الثاني” لتاريخ كان المتجر فيه يعمل بنظام شفت واحد.

#### المشكلة الثانية: خطأ `sender_type` عند إعادة الإرسال للمحاسب

- جدول `notifications.sender_type` في القاعدة الحالية لا يقبل `store_owner` ضمن enum، لذلك تم تغيير إشعارات طلبات الشفت الناقص لتستخدم `sender_type = user`.
- حتى لا نفقد المعنى، تم حفظ `sender_role = store_owner` داخل `data` للإشعار.
- تم استخراج إنشاء إشعار المحاسب داخل `ShiftGapRequestService::notifyAccountant` حتى لا يتكرر نفس الخطأ في الإنشاء وإعادة التعيين.

#### قاعدة متابعة

أي تغيير مستقبلي في عدد الشفتات يجب أن يترك أثرًا تدقيقيًا في `logs`، وأي إشعار جديد يجب أن يستخدم قيم enum الموجودة في جدول `notifications` فقط، مع وضع الأدوار التفصيلية داخل `data` إذا احتجناها.

### 21. الخطوة التالية: استخراج تاريخ إعدادات الشفتات إلى خدمة مشتركة

#### الخطوة الأولى: خدمة موحدة لتسجيل تغييرات عدد الشفتات

- تم إنشاء `ShiftSettingsHistoryService` لتكون مسؤولة عن تسجيل تغيير عدد الشفتات بدل إبقاء تفاصيل السجل داخل `StoreController`.
- عند تعديل المتجر، يمر تسجيل الفرق بين العدد القديم والجديد عبر الخدمة الجديدة، مع حفظ المستخدم المنفذ ووقت التعديل داخل تفاصيل السجل.
- الفائدة: أي شاشة أو أمر مستقبلي يغيّر عدد الشفتات سيستخدم نفس الخدمة بدل تكرار صيغة `store_shift_settings_changed` أو نسيان بيانات التدقيق.

#### الخطوة الثانية: خدمة موحدة لقراءة تاريخ التحويل إلى شفتين

- أصبح `ShiftLifecycleService` يستدعي `ShiftSettingsHistoryService::firstUpgradeToTwoShiftsDate` بدل امتلاك دالة داخلية خاصة بقراءة سجلات التغيير.
- هذا يبقي منطق تحديد تاريخ بداية نظام الشفتين في مكان واحد، وهو نفس المكان المسؤول عن تسجيل ذلك التاريخ.
- الفائدة العملية: مراجعة الشفتات الناقصة لن تطلب شفتًا ثانيًا لتاريخ أقدم من تحويل المتجر إلى شفتين، مع الحفاظ على توافق خلفي للمتاجر التي عُدّلت قبل إضافة السجل التدقيقي.

#### قاعدة متابعة

أي تعديل لاحق في إعدادات الشفتات يجب أن يمر عبر `ShiftSettingsHistoryService`، وأي خدمة تحتاج معرفة تاريخ التحويل إلى شفتين يجب أن تقرأه منها بدل بناء استعلام جديد داخل الكنترولر أو خدمة أخرى.

### 22. العودة إلى نطاق المستند: استخراج ملخص صفحة المتجر بدل إضافة Helper صغير

#### الخطوة الأولى: نقل مسؤولية إحصاءات صفحة المتجر من الكنترولر

- تم إنشاء `StoreDashboardService` لنقل كتلة إحصاءات صفحة عرض المتجر من `StoreController::show`.
- انتقلت إلى الخدمة استعلامات مبيعات اليوم، مبيعات الشهر، عدد الفواتير، الربح الشهري، عدد المحاسبين/الموظفين/الأقسام/المنتجات، الاستهلاك الداخلي، أفضل المنتجات، بيانات الرسم البياني، وسجل العمليات الأخير.
- أصبح `StoreController::show` يركز على HTTP والصلاحية وتجهيز الحالات الخاصة بزر استعادة الشفت الثاني فقط، بدل بناء كل أرقام الصفحة داخله.

#### الخطوة الثانية: حذف التكرار داخل استعلامات الصفحة نفسها

- داخل `StoreDashboardService` تم توحيد شرط استبعاد قيود الفواتير اليدوية في `salesWithoutManualInvoiceEntries` بدل تكراره مع كل استعلام.
- تم توحيد أنواع البيع المعتمدة في ملخص المتجر داخل ثابت واحد `INCLUDED_SALE_TYPES` حتى لا تختلف أرقام اليوم والشهر والرسم البياني.
- تم حذف imports لم تعد مستخدمة من `StoreController` بعد نقل مسؤولية الملخص.

#### قاعدة متابعة

أي تعديل لاحق على أرقام صفحة عرض المتجر يجب أن يبدأ من `StoreDashboardService`، أما `StoreController::show` فيبقى طبقة رقيقة لا تحتوي استعلامات تجميع أو حسابات مالية للوحة المتجر.

### 23. خطوة كبيرة إضافية: استخراج إحصاءات المتجر المتقدمة من StoreController

#### الخطوة الأولى: نقل API الإحصاءات المتقدمة إلى خدمة مستقلة

- تم إنشاء `StoreAdvancedStatsService` لنقل كتلة `StoreController::getAdvancedStats` التي كانت تجمع مبيعات شهرية، إحصاءات منتجات، منتجات قليلة المخزون، وإحصاءات موظفين داخل الكنترولر.
- أصبح الكنترولر يتحقق من صلاحية الوصول فقط ثم يعيد JSON ناتج الخدمة، بدون استعلامات تجميع أو `selectRaw` داخل طبقة HTTP.
- هذه الخطوة تكمل استخراج صفحة المتجر: `StoreDashboardService` لملخص العرض، و`StoreAdvancedStatsService` للبيانات المتقدمة التي تطلبها الواجهة عبر JSON.

#### الخطوة الثانية: تنظيف الاعتمادات بعد النقل

- تم حذف imports الخاصة بـ `Product` و`Employee` من `StoreController` لأنها أصبحت مستخدمة داخل الخدمة فقط.
- بقيت استعلامات `DB` داخل الكنترولر فقط للأجزاء التي لم تُنقل بعد مثل النقل بين تواريخ الشفت والحذف النهائي، وليس لإحصاءات المتجر.

#### قاعدة متابعة

أي إضافة لاحقة لإحصاءات مخزون/مبيعات/موظفين في صفحة المتجر أو API الإحصاءات يجب أن تذهب إلى `StoreAdvancedStatsService` أو خدمة إحصاءات أكثر تخصصًا، وليس إلى `StoreController`.

### 24. تصحيح المسار: من تكاثر الخدمات إلى خدمات مشتركة وخارطة طريق التنفيذ

#### التشخيص الحالي

- التطبيق السابق للمستند حقق نقل كتل مهمة من الكنترولرات، لكنه بدأ يميل إلى إنشاء خدمات مرتبطة بصفحات أو كنترولرات محددة مثل `StoreDashboardService` و`StoreAdvancedStatsService` بدل بناء خدمات مفاهيمية مشتركة دائمًا.
- وجود خدمة ليس نجاحًا بحد ذاته؛ النجاح هو حذف منطق متكرر أو مالي أو تشغيلي من أكثر من مكان ووضعه في طبقة مشتركة قابلة للاختبار.
- ما زالت هناك دوال قديمة داخل الكنترولرات، خصوصًا في لوحة المحاسب والمبيعات اليومية، تبني استعلامات `Sale` و`Expense` و`Withdrawal` وتنسق صفوف العمليات داخل نفس الملف.
- ما زالت بعض الخدمات الجديدة تحتاج مراجعة؛ لأن نقل الكود إلى Service لا يعني أن الكود أصبح منظمًا إذا بقيت الاستعلامات الطويلة، أسماء المتغيرات المبهمة، أو `find` داخل `map`.
- يوجد `catch` عام في عدة مواضع، وبعضه يخفي فشلًا مهمًا بإرجاع قيم فارغة بدل معالجة استثناء محدد أو ترك Laravel يتعامل مع الخطأ.

#### قواعد تصحيح المسار

1. لا تنشأ خدمة جديدة لمجرد أن الكنترولر كبير؛ يجب أن تمثل الخدمة مفهومًا تجاريًا أو تشغيليًا مشتركًا.
2. أي خدمة جديدة يجب أن تحقق واحدًا على الأقل من التالي:
   - تستخدمها أكثر من طبقة أو أكثر من Controller.
   - تنقل مسؤولية كاملة مثل عمليات مالية، فترة محاسبية، تنسيق عمليات، تقرير، أو دورة شفت.
   - تحذف كتلة فعلية من Controller أو Blade وتمنع تكرارها في مكان آخر.
3. قبل إضافة أي خدمة جديدة يجب عمل جرد للدوال القديمة والاستدعاءات الحالية باستخدام `rg`، ثم تحديث جدول الدوال المرشحة للحذف أو التحويل.
4. أي `catch (\Exception)` أو `catch (\Throwable)` داخل منطق مالي أو شفتات يجب مراجعته: إما استثناء محدد، أو رسالة واضحة، أو ترك الخطأ يصعد بدل إخفائه.
5. أسماء المتغيرات في المنطق المالي أو الشفتات يجب أن تصف معناها التجاري؛ لا تستخدم أسماء مثل `$model`, `$type`, `$item`, `$rows` إلا في نطاق صغير وواضح جدًا.

#### خارطة الطريق العملية

##### المرحلة الأولى: جرد وتحليل قبل أي نقل جديد

- إنشاء قائمة بالدوال القديمة داخل `DashboardController`, `DailySalesController`, `StoreController`, و`UserDashboardController`.
- تحديد كل دالة هل هي: مستخدمة، wrapper مؤقت، مرشحة للحذف، أو يجب نقلها إلى خدمة مشتركة.
- تحديث جدول الدوال القديمة في هذا المستند قبل تنفيذ النقل.
- معيار الإغلاق: لا يبدأ أي نقل جديد قبل وجود جدول واضح يذكر البديل الجديد وملاحظات الحذف الآمن.

##### المرحلة الثانية: خدمة العمليات المالية المشتركة

- إنشاء خدمة مفاهيمية مشتركة للعمليات المالية مثل `AccountingOperationFeedService` أو `AccountingOperationQueryService` بدل خدمات خاصة بصفحة واحدة.
- نطاقها الأول: `Sale`, `Expense`, `Withdrawal`.
- تنقل من لوحة المحاسب والمبيعات اليومية والمتجر منطق:
  - جلب عمليات الشفت المفتوح.
  - جلب آخر العمليات.
  - جلب تفاصيل عمليات شفت محدد.
  - دمج وترتيب العمليات.
  - منع تكرار شروط `store_id`, `business_date`, `daily_balance_id`, و`created_at`.
- معيار الإغلاق: دوال مثل `getLastOperations`, `getShiftOperationDetails`, وأجزاء من استعلامات المبيعات اليومية لا تبقى تبني الثلاثي المالي يدويًا داخل Controller.

##### المرحلة الثالثة: Presenter مشترك لتنسيق صف العملية

- إنشاء `AccountingOperationPresenter` أو اسم مشابه لتنسيق صفوف المبيعات والمصروفات والسحوبات.
- ينقل إليه منطق تسمية المنفذ، نوع العملية، المبلغ، طريقة الدفع، نص العرض، وروابط التفاصيل.
- يمنع تكرار `formatOp` أو أسماء المنفذين بين لوحة المحاسب، المبيعات اليومية، وتقارير الشفت.
- معيار الإغلاق: لا يوجد تنسيق صف عملية مالي داخل Controller أو Blade إلا كعرض بسيط لقيم جاهزة.

##### المرحلة الرابعة: تنظيف الخدمات التي أصبحت خاصة بالصفحات

- مراجعة `StoreDashboardService`, `StoreAdvancedStatsService`, و`StoreDetailsService`.
- إما دمج ما يتكرر منها في خدمة مفاهيمية مثل `StoreAnalyticsService` أو تقسيمها إلى خدمات أصغر لكن مشتركة مثل `StoreFinancialSummaryService` و`StoreInventorySummaryService`.
- معالجة الاستعلامات الطويلة و`find` داخل `map` وأسماء المتغيرات المبهمة داخل هذه الخدمات.
- معيار الإغلاق: لا تكون الخدمة مجرد نسخة من دالة Controller باسم جديد؛ يجب أن تكون قابلة لإعادة الاستخدام أو الاختبار كمسؤولية واضحة.

##### المرحلة الخامسة: مراجعة الاستثناءات العامة

- جرد كل `catch` في `app/`.
- تحويل الاستثناءات العامة في المنطق المالي والشفتات إلى استثناءات محددة أو رسائل واضحة.
- منع إرجاع Collection أو Array فارغة عند فشل مهم دون تنبيه واضح؛ لأن ذلك قد يخفي نقص بيانات مالية.
- معيار الإغلاق: لا يوجد `catch (\Exception)` في مسار مالي أو شفتات إلا بسبب موثق ومحدد.

##### المرحلة السادسة: اختبارات مقارنة قبل/بعد

- إضافة اختبارات أو أوامر فحص للمقارنة بين نتائج الاستعلامات القديمة والجديدة قبل حذف الدوال القديمة.
- الأولوية لاختبارات:
  - آخر العمليات في لوحة المحاسب.
  - تفاصيل عمليات الشفت.
  - المبيعات اليومية حسب `business_date` و`daily_balance_id`.
  - تقارير التكلفة والربح.
- معيار الإغلاق: لا تحذف دالة قديمة مالية أو متعلقة بالشفتات إلا بعد وجود فحص يثبت أن البديل يعطي نفس النتيجة أو يوضح سبب الاختلاف.

#### جدول أولي للدوال القديمة المرشحة للتحويل في المسار القادم

| الدالة/المنطق القديم | الحالة | البديل المقترح | ملاحظات الحذف الآمن |
|---|---|---|---|
| `DashboardController::getLastOperations` | مرشحة للنقل | `AccountingOperationFeedService` | يجب مقارنة عدد وترتيب العمليات قبل/بعد |
| `DashboardController::getShiftOperationDetails` | مرشحة للنقل الجزئي | `AccountingOperationQueryService` + Presenter | يجب الحفاظ على تفاصيل المبيعات والمصروفات والسحوبات والتحصيلات |
| `DashboardController::formatOp` | مرشحة للنقل | `AccountingOperationPresenter` | يجب توحيد أسماء المنفذين وطريقة الدفع مع `PaymentTypeLabel` |
| استعلامات `Sale/Expense/Withdrawal` في `DailySalesController` | مرشحة للتوحيد | `AccountingOperationQueryService` | يجب الحفاظ على fallback للبيانات القديمة |
| استعلامات `Sale/Expense/Withdrawal` في `StoreController` لمسارات الشفت | مرشحة للتوحيد | `AccountingOperationQueryService` | يجب الحذر في مسارات نقل/إغلاق الشفت لأنها تعدل بيانات |
| `StoreDetailsService` كسلسلة استعلامات طويلة | تم جزئيًا | `FinancialSummaryService` + helpers داخل الخدمة | أزيلت حسابات المبيعات/المصروفات الشهرية المباشرة و`find` داخل `map`، وما زال فصل إحصاءات المخزون والموظفين ممكنًا لاحقًا |

### 25. توحيد المستندات الثلاثة وحذف المنجز من قائمة العمل النشطة

#### ما لا يدخل في قائمة العمل النشطة بعد الآن

تبقى القواعد والشروط والتوثيق التاريخي كما هي، لكن لا يعاد فتح البنود التالية كخطوات جديدة إلا إذا ظهر خلل واضح؛ لأنها نُقلت أو بدأ تنفيذها بالفعل:

- استخراج التقرير الشهري وتقرير البحث الشامل من `StoreController` إلى خدمات تقارير.
- استخراج بحث المنتجات إلى `ProductSearchService` مع بقاء مراجعة أشكال العرض لكل شاشة عند الحاجة.
- إضافة `PaymentTypeLabel` لتوحيد تسميات الدفع.
- إضافة `QrCodeSvg` وتوحيد مسار فواتير PDF/print بدرجة أولية.
- إنشاء `SalesCostService` كبداية لمصدر تكلفة مركزي، مع بقاء استكمال التخلص من fallback القديم ضمن خارطة الطريق المالية.
- إنشاء خدمات طلبات الشفت الناقص ومراجعة الشفتات، مع بقاء توحيد عمليات `Sale/Expense/Withdrawal` ضمن العمل النشط.
- نقل ملخصات صفحة المتجر والإحصاءات المتقدمة من `StoreController`، وتم ربط ملخصات `StoreDetailsService` المالية بـ `FinancialSummaryService` جزئيًا، مع بقاء مراجعة إحصاءات المخزون والموظفين لأنها قد تكون خدمات صفحة لا خدمات مفاهيمية مشتركة.

#### ما يبقى ضمن قائمة العمل النشطة المشتركة بين المستندات

- خدمة عمليات مالية مشتركة لـ `Sale`, `Expense`, و`Withdrawal` تستخدمها لوحة المحاسب، المبيعات اليومية، مراجعة الشفتات، والتقارير.
- Presenter موحد لصف العملية المالية بدل `formatOp` وتنسيقات Blade/Controller المتفرقة.
- خدمة/نطاق موحد للفترة المالية والشفتات يراجع ما بين `ShiftLifecycleService`, `forOpenAccountingShift`, وخارطة `ShiftWindowService` المالية.
- مراجعة خدمات المتجر الحالية ودمج ما هو خاص بصفحة واحدة في خدمات مفاهيمية إذا تكرر استخدامها.
- تنظيف الدوال القديمة بعد فحص الاستدعاءات وليس بمجرد إنشاء خدمة جديدة.
- مراجعة `catch` العام في المسارات المالية والشفتات.
- اختبارات أو أوامر مقارنة قبل حذف أي منطق مالي قديم.

#### قاعدة عدم التعارض بين المستندات الثلاثة

عند وجود تعارض ظاهري بين المستندات، تعتمد القاعدة التالية:

1. مستند `FINANCIAL_REFACTORING_ROADMAP_AR` يحكم المعادلات المالية وتوحيد النتائج بين الصفحات.
2. مستند `EMPLOYEE_ACCOUNTANT_OPERATIONS_REVIEW_AR` يحكم قواعد الموظفين والمحاسبين والربط بالشفت والتاريخ المحاسبي.
3. هذا المستند يحكم طريقة إعادة الهيكلة: متى ننقل، أين ننقل، ومتى نحذف الدوال القديمة.
4. إذا تعارضت خطوة refactor مع قاعدة مالية أو قاعدة موظفين، لا تنفذ حتى توثق المقارنة ويحدد مصدر الحقيقة.

### 26. تنفيذ الخطوة الكبيرة الأولى: خدمة العمليات المالية المشتركة

- تم إنشاء `AccountingOperationFeedService` كبداية عملية لخدمة مشتركة تجمع عمليات `Sale`, `Expense`, و`Withdrawal` بدل بناء آخر العمليات وتفاصيل الشفت داخل `DashboardController`.
- تم إنشاء `AccountingOperationPresenter` لنقل تنسيق صف العملية المالية، أسماء المنفذين، وصف البيع، وطريقة الدفع من الكنترولر إلى Presenter قابل لإعادة الاستخدام.
- تم حذف دوال `getLastOperations`, `getShiftOperationDetails`, `formatOp`, و`operationExecutorName` من لوحة المحاسب؛ لأن الخدمة الجديدة أصبحت مسؤولة عن جلب آخر العمليات وتفاصيل عمليات الشفت.
- هذه الخطوة لا تكمل كل خارطة الطريق، لكنها تنفذ أول جزء كبير مشترك بين المستندات: إخراج الثلاثي المالي `Sale/Expense/Withdrawal` من الكنترولر كبداية قابلة للتوسيع إلى المبيعات اليومية ومراجعة الشفتات.

#### ما يبقى بعد هذه الخطوة

- ربط `DailySalesController` بالخدمة نفسها بدل بناء استعلاماته الخاصة.
- نقل أي منطق مشابه في `StoreController` ومسارات مراجعة الشفتات إلى نفس الخدمة.
- إضافة اختبارات مقارنة قبل/بعد لآخر العمليات وتفاصيل عمليات الشفت قبل حذف أي fallback إضافي.

### 27. تنفيذ الخطوة الكبيرة التالية: توحيد نوافذ الشفت والفترة المحاسبية

- تم إنشاء `ShiftWindowService` لتوحيد بناء نوافذ الشفت اليومية بدل بقاء `buildShiftWindows`, `applyPeriodFilter`, `applyOutgoingShiftFilter`, و`resolveShiftKey` داخل `DailySalesController`.
- أصبح `DailySalesController` يستخدم الخدمة لبناء نوافذ اليوم، fallback اليوم التقويمي، فلترة مبيعات الفترة، فلترة المصروفات والسحوبات، وحل مفتاح الشفت للعملية.
- هذه الخطوة تربط مستند SRP مع خارطة الطريق المالية، لأن توحيد نوافذ الشفت شرط سابق لتوحيد الملخص المالي لاحقًا.

#### إدراج خطة توحيد ملفات عمليات المالك والمحاسب

- تم اعتماد أن توحيد ملفات عمليات المالك والمحاسب الخاصة بالموظف لا يبدأ من الواجهة، بل يبدأ بعد توحيد العمليات المالية والشفتات.
- المسار المخطط: خدمات مشتركة للعمليات المالية أولًا، ثم خدمات صلاحيات الموظف/المحاسب، ثم توحيد الواجهات أو الكنترولرات المتكررة.
- الهدف ليس نقل ملفات فقط، بل تقليل الملفات المتكررة دون كسر قواعد الموظف والمحاسب المثبتة في مستند العمليات.

### 28. تنفيذ خطوة كبيرة مرتبطة بعمليات الموظف: خدمة عمليات الموظف المشتركة

- توجد خدمات شفت سابقة فعلًا، وأهمها `ShiftLifecycleService`؛ دورها تحديد سياق الشفت المفتوح والتاريخ المحاسبي عند إنشاء عملية جديدة.
- لذلك لا يتم اعتبار `ShiftWindowService` بديلًا عنها، بل خدمة عرض/فلترة لنوافذ الشفتات المغلقة أو المفتوحة عند قراءة العمليات اليومية.
- تم بدء توحيد عمليات الموظف تدريجيًا بإنشاء `EmployeeOperationService` بدل تكرار تسجيل السحب والغياب بين مسارات المالك ومسارات المحاسب.
- تم نقل قواعد مشتركة إلى الخدمة: تحديد تاريخ العملية، احترام شفت مرتجع للمحاسب عند وجوده، منع التكرار، إنشاء العملية، تسجيل `EmployeeLog`، وتسجيل `Log` العام.
- بقيت قواعد الواجهة والصلاحيات داخل الكنترولرات مؤقتًا حتى لا يتم دمج ملفات المالك والمحاسب دفعة واحدة قبل اكتمال الخدمات المشتركة.

#### قرار معماري بخصوص الإشعارات والسجلات

- الإشعارات الداخلية يجب أن تصبح خدمة مستقلة تدريجيًا، لكن لا تحتاج Queue في هذه المرحلة؛ المطلوب هو واجهة خدمة موحدة تنشئ سجل الإشعار وتبثه بشكل اختياري ومتزامن إذا كانت قناة البث متاحة.
- السجلات يجب أن تصبح خدمة تدقيق مستقلة داخل التطبيق أولًا، مع Events متزامنة لا Queue؛ أي حدث مهم يستدعي Listener فوري يكتب في جدول `logs`.
- `Event-Driven Logging` مناسب بشرط أن يكون synchronous في البداية، لأن الهدف فصل مصدر الحدث عن طريقة الكتابة وليس إدخال طوابير تشغيل قد تسبب مشاكل استضافة.
- `Rotating Log Files` مناسب لسجلات Laravel التقنية فقط، وليس بديلًا عن سجلات التدقيق التجارية الموجودة في قاعدة البيانات.
- `Independent DB` مؤجل؛ لا يبدأ إلا إذا كبر حجم جدول السجلات وأصبح يؤثر على الأداء، والأفضل قبل ذلك إضافة أرشفة/تنظيف مضبوط وفهارس مناسبة.

### 29. خطوة تقليل عدد الخدمات بدل زيادتها

- تم حذف `StoreAdvancedStatsService` ودمج مسؤوليته داخل `StoreDashboardService::advancedStats` لأن الخدمتين كانتا تخصان نفس صفحة المتجر ونفس نوع بيانات اللوحة.
- هذا القرار يثبت قاعدة جديدة: لا ننشئ خدمة لكل endpoint إذا كانت الخدمة الجديدة مجرد امتداد لنفس مفهوم الصفحة أو نفس مصدر البيانات.
- عند وجود خدمة صفحة قائمة، يضاف لها أسلوب واضح الاسم بدل إنشاء ملف جديد، ما دام الحجم والسلوك ما زالا ضمن نفس السياق.
- الخدمات الجديدة لاحقًا يجب أن تكون مفاهيمية ومشتركة مثل عمليات مالية، عمليات موظف، إشعارات، سجلات، أو صلاحيات؛ وليست مجرد تقسيم ملف كبير إلى ملفات صغيرة بلا إعادة استخدام.

### 30. ثلاث خطوات في خطوة واحدة: إصلاح النطاق، تسمية أوضح، وتقليل كتلة المبيعات اليومية

- تم إصلاح خطأ `Undefined variable $shiftWindowService` في ملخصات الشفت داخل `DailySalesController` بتمرير الخدمة صراحة إلى دالة بناء الملخص.
- تم نقل كتلة حساب ملخص الشفت من closure كبيرة داخل `index` إلى دوال داخلية واضحة الأسماء مثل `buildShiftSummary`, `cashAmountForSale`, `cardAmountForSale`, و`tadlilWorkAmount` بدل توسيع الكنترولر بخدمة جديدة.
- تم تحسين أسماء متغيرات المسار المتأثر: `salesWithItemsQuery`, `saleRows`, `saleItemRow`, `groupedSale`, `shiftSummary`, و`shiftWindow` حتى تحمل معنى العمل بدل أسماء عامة مثل `query`, `row`, أو `s`.
- هذه الخطوة مقصودة كبديل عن إنشاء خدمة جديدة: عندما يمكن تقليل الغموض داخل الملف بدوال صغيرة وأسماء واضحة دون تضخيم عدد الخدمات، فهذا هو الخيار الأفضل.
