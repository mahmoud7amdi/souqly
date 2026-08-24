# NOTES.md — Souqly (procure2pay rebuild on Laravel 13)

## 🔑 Development sign-in — بيانات الدخول

| | |
|---|---|
| **URL** | `/login` |
| **Username** | `admin` |
| **Password** | `SEED_ADMIN_PASSWORD` in your `.env` — **not stored in this repository** |

The password is deliberately absent from version control: this account is
unrestricted, so it is a full-control credential and a committed one would be
published by the first push. Set `SEED_ADMIN_PASSWORD` in `.env` (see
`.env.example`) before seeding — `AdminUserSeeder::password()` reads it via
`config('constants.seed_admin_password')` and **throws** if it is unset, so the
account can never fall back to a default. See §12.2.

**Login is by username, not email.** The whole auth stack is username-based:
`LoginController::login()` validates `username` + `password` only,
`resources/views/auth/login.blade.php` has a single `username` field, and
`users.email` is nullable and non-unique (it is never an auth credential).
There is no email/password-reset flow.

Created by `database/seeders/AdminUserSeeder.php` (idempotent — re-running never
overwrites an existing `admin`). It provisions a complete tenant: business
«سوقلي», Admin + Cashier roles, one location, invoice scheme + layout, default
unit, tax rate and the walk-in customer. To (re)create it:

```
php artisan db:seed --class=AdminUserSeeder    # just the account
php artisan db:seed                            # currencies + permissions + account
```

The account is **unrestricted**: the tenant Admin role deliberately holds no
explicit permissions, and `Gate::before()` in `AppServiceProvider` grants an
admin every ability — matching the source system. See §8.

---

> **Note on this file:** it was found duplicated on disk mid-build (a second full
> copy appended inside a half-finished sentence). Rewritten clean on 2026-08-22.
> Nothing was lost — every entry below is current.

This is the decision log for rebuilding `procure2pay` on Laravel 13. Every deviation from
the source documentation is recorded with its reason. Nothing here is a silent assumption.

---

## ◀️ عند العودة ابدأ هنا / Start here on return

**آخر جلسة: 2026-08-24.** هذه هي العلامة الوحيدة الموثوقة للاستئناف.

### 1. آخر ما اكتمل

**البند 7 — التقارير، الدفعة الأولى: ✅ مكتمل بالكامل — كودًا واختبارًا وتوثيقًا.** التوثيق
المعماري الكامل في **§13**، والخطة المعتمدة في
`C:\Users\mohamed\.claude\plans\adaptive-plotting-dongarra.md`.

المُنجَز:

| الطبقة | الملف | الحالة |
|---|---|---|
| الخدمة | `app/Services/ReportService.php` | ✅ جديد — كل الأرقام هنا، والمتحكِّم رقيق بالتصميم |
| المتحكِّم | `app/Http/Controllers/ReportController.php` | ✅ جديد — المركز + ٥ تقارير + `export()` واحد مُعامَل بقائمة بيضاء |
| المسارات | `routes/web.php` | ✅ مجموعة `reports.` — المركز والخمسة و`{report}/export` |
| مكوّن الفلترة | `resources/views/components/report-filters.blade.php` | ✅ جديد — زوج التواريخ دائمًا، والباقي بـ `:fields` |
| لوحة التحكم | `app/Http/Controllers/HomeController.php` | ✅ `dateRange()` تُفوَّض الآن لـ `ReportService`، و`FormattingService` أُزيلت من المتحكِّم |
| الشاشات | `resources/views/report/{index,purchase-sell,stock,profit-loss,tax,expenses}.blade.php` | ✅ الستة موجودة ومكتملة |
| الترجمة | `lang/ar/lang_v1.php` + `lang/en/lang_v1.php` | ✅ 68 مفتاحًا جديدًا في كل ملف (974/974) |
| الاختبارات | `tests/Feature/ReportsTest.php` (17) + `ApiResponseTest.php` (14) | ✅ خضراء داخل 102 اختبار / 494 تأكيد |
| التوثيق | **NOTES §13** | ✅ حدّ الخدمة، قرار COGS، القائمة البيضاء، السبعة المؤجَّلة، الأخطاء الثلاثة |

**وأهم ما في هذه الدفعة ليس تقريرًا.** اختبار تقرير الأرباح كشف **خطأ إنتاج حقيقيًا في
`SellService::syncLines()`**: كانت مِسْحة التنظيف تحذف خطوط مكوّنات الكومبو التي أُنشئت للتوّ
وتُحرِّر المخزون الذي استهلكته، في **كل** عملية بيع كومبو — لا في التعديل وحده. النتيجة الظاهرة
صفرُ تكلفة، فكل كومبو يبدو ربحًا خالصًا في التقرير الوحيد الذي يتّخذ المالك قراره عليه. أُصلح
ومُوثَّق في §13.6.

### 2. البند التالي: 8 — Settings

لا شيء متبقٍّ من البند 7 يمنع البدء. المؤجَّل منه سبعة تقارير مسمّاة صريحةً في §10.2 بند 7، وهي
تَرِث البنية التحتية كلها ولا تحتاج جديدًا منها.

**البند 8 — Settings.** المتحكِّمات المطلوبة: `BusinessController`، `BusinessLocationController`،
`InvoiceSchemeController`، `InvoiceLayoutController`، `BarcodeController`، `PrinterController`،
`NotificationTemplateController`، `RoleController`، `ManageUserController`. ثم 9 → 10 → 11 → 12
(Printing، Offline PWA، شاشات الوحدات، الأوامر المجدولة). `inventory.index` تبقى خارج النطاق
(تنتمي لـ `inventorymanagement`، البند 11).

### 3. القرارات — **لا شيء ينتظر إذنك**

القرارات كلها محسومة، ولا يوجد سؤال مُعطِّل:

- **نطاق البند 7** = دفعة أولى ٥ تقارير + البنية التحتية. ✅ محسوم ومُنفَّذ
- **§12.3** = لا نلمس `Gate::before()`؛ الأدمن يبقى غير مقيَّد، والتقارير تحترم الصلاحيات لغير
  الأدمن — وهو ما يفعله `permit()` أصلًا. ✅ محسوم **بلا أي تغيير سلوكي**، ويبقى 🟡 للمراجعة عند
  أول متطلَّب حقيقي متعدد المديرين (المُحفِّز مذكور في §12.3).
- **§12.5** = ✅ أُغلق بـ `ApiResponseTest`.
- **القيود المقفلة:** #2 لا تقارير GST هندية (السوق مصر — مُستبعدة فعلًا في
  `app/Support/Permissions.php:14`)، #3 عربية + RTL كاملة على كل شاشة **وكل تقرير** بمعايير
  القبول في §5، و#8 استقلالية كاملة في القرارات التقنية (تُسجَّل في §8) — وأسئلة النطاق وحدها
  تعود إليك.
- **الشروط الجارية:** commit بعد كل بند مكتمل ومختبر، **بدون push**، وتوثيق كامل في NOTES.md.

### 4. ما يجب قراءته قبل لمس أي شاشة

**§11.4 و§11.7 و§12.4.** يحملان مفردات التقسيم ومبادئ التصميم والمصيدتين اللتين كلّفتا وقتًا:
`.section-head` **بلا هامش أعلى** (فَراغه يأتي من `.section`/`.section-tight` قبله)، و«grep
لأسماء الأصناف لا يقيس التزام §11.4» لأن `<x-panel quiet>` يُصدرها طبقةً أدنى.

**وقيد صارم يبقى ساريًا على كل تقرير قادم:** كل تقرير يجب أن يُعرَض صحيحًا **بلا أي فلاتر**، لأن
مَشْي المسارات يفتحه عاريًا — وافتراضُ «الشهر الحالي» في `dateRange()` هو ما يجعل ذلك ممكنًا؛ فلا
تقرير يجوز أن يشترط query string.

**ودرسان من هذه الدفعة يتكرران في كل ما بعدها:**

- **أي اختبار على مستوى الخدمة لِما هو مقيَّد بالفروع يجب أن يُوثِّق دخولًا** — وإلا قاس الفراغَ
  بثقة. `permittedLocations()` تُحَلّ مقابل `auth()->user()`، و`createTenant()` لا تُوثِّق أحدًا
  (§13.7).
- **الأصفار المتماثلة توقيعُ استعلامٍ فارغ، لا توقيعُ حسابٍ خاطئ.** ثلاثة عشر فشلًا متطابقًا كان
  لها سبب واحد، وإصلاحٌ واحد حلَّ أحدَ عشر منها.

### 5. حالة التحقق — الصادقة، بلا أرقام من الذاكرة

**نُفِّذ فعليًا ونجح:**

- `php artisan test` → **102 اختبارًا / 494 تأكيدًا، كلها خضراء.**
- **تكافؤ الترجمة: `ar = 974` مفتاحًا، `en = 974`** — تكافؤ تام (كان 906/906، +68 لكل ملف).
- المفاتيح الـ68 الجديدة موجودة في الملفين، **كل مفتاح مرة واحدة بالضبط** — لا تكرار يكتب فوق
  مفتاح قائم (وهو خطأ لا يلتقطه `php -l` لأن تكرار مفتاح مصفوفة ليس خطأ صياغة).
- فحصان بالتحوير (mutation) على COGS، وكلاهما عَضَّ — الجدول في §13.8.
- مستخدم غير أدمن يملك `stock_report.view` فقط: بطاقة واحدة في المركز و403 على الأربعة الباقية —
  مُؤكَّد باختبار في `ReportsTest`، لا بالعين. (ويحتاج `'allow_login' => 1` وإلا صدَّته
  `CheckUserLogin` بـ 302 وقاس الاختبارُ بوابةَ الدخول لا بوابات التقارير.)

**متبقٍّ، ولا يمنع البند 8:**

- `npm run build` — مُصنِّف أمان Bash/PowerShell كان متوقفًا مرارًا في هذه الجلسة. التوقع
  ≈121.26 kB CSS، إذ لم يُضَف CSS إطلاقًا: `.tile` و`.rise-group` و`<x-stat>` و`.filter-bar`
  تحمل متطلبات التصميم أصلًا (§13.10).
- فتح كل تقرير عاريًا بالعربية بالعين، وطباعة تقرير واحد — الأول مُغطّى آليًا بمَشْي المسارات،
  والثاني تكفله كتلة الطباعة التي تُخفي `.filter-bar` أصلًا.
- ⚠️ **ثغرة تغطية مذكورة بصراحة:** قرار «COGS شامل الضريبة» غير مثبَّت باختبار، لأن مُثبِّت
  `buy()` يجعل `purchase_price` و`purchase_price_inc_tax` متساويين — §13.8.

---

## 0. Environment as built

| Item | Value |
|---|---|
| Framework | Laravel 13.26.1 |
| PHP | 8.4.21 |
| Database | MySQL 8.4.3, `souqly` (app) + `souqly_test` (tests), `utf8mb4_unicode_ci` |
| Node / npm | 22.19.0 / 10.9.3 |
| Frontend | Blade + Tailwind CSS 4 + Vite 8, dependency-free JS |
| Market | **Egypt** — Arabic-first UI, EGP default, Paymob gateway |

### 0.1 Changes made outside the project folder

1. **MySQL started manually.** The `MySQL80` service could not be started without
   elevation, so Laragon's `mysqld.exe` was launched directly against
   `C:\laragon\data\mysql-8.4`. If you normally start MySQL from the Laragon UI, kill the
   stray `mysqld` first. Nothing in the project depends on how MySQL is launched.
2. **PHP `zip` extension enabled** — line 813 of
   `C:\laragon\bin\php\php-8.4.21-Win32-vs17-x64\php.ini`. Required by
   `maatwebsite/excel` for xlsx import/export. Backup at `php.ini.souqly-backup`;
   revert by re-commenting `extension=zip`.

---

## 1. Your decisions (locked)

| # | Decision | Effect |
|---|---|---|
| 1 | **No KYC / client master-data tables** | The 14 undocumented tables (§4) stay uncreated. Permanently out of scope. |
| 2 | **No Indian GST reports** — market is Egypt | `enable_gst_report_india` removed from `config/constants.php` entirely; `ENABLE_GST_REPORT_INDIA` removed from `.env`; `gstSalesReport`/`gstPurchaseReport` excluded. |
| 3 | **Full Arabic + RTL on every screen and report** | Hard requirement; implementation and acceptance criteria in §5. |
| 4 | **Paymob only** | `PaymobGateway` implemented behind a `PaymentGateway` contract. Stripe, PayPal, Razorpay, Paystack, PesaPal and Flutterwave are **removed from the plan** — not installed, not referenced. |
| 5 | **Pusher / realtime notifications** | `pusher/pusher-php-server:^7.2` installed, `BROADCAST_CONNECTION=pusher`, `routes/channels.php` with tenant-scoped channels. |
| 6 | **`transactions.exchange_rate` → `decimal(22,4)`** | Applied and verified in the live schema. |
| 7 | **Manufacturing + Breakdown removed** | See §2. |
| 8 | **Full autonomy on all other technical decisions** | Every such decision is logged in §8. |

---

## 2. Manufacturing + Breakdown (disassembly) — REMOVED

Deleted per your instruction:

- `2026_01_02_000300_create_manufacturing_module_tables.php` — deleted. It created
  `mfg_recipes`, `mfg_recipe_ingredients`, `mfg_ingredient_groups`, `break_down_recipes`,
  `break_down_recipes_ingredients`, `break_down_ingredient_groups`, plus
  `business.manufacturing_settings`, `transactions.mfg_*` and
  `transaction_sell_lines.mfg_*`.
- `purchase_lines.mfg_quantity_used` — removed.
- `product_price_history.change_type` — `production` and `recipe_update` removed; the enum
  is now `manual_update | purchase | bulk_update`.
- Transaction types `production_purchase`, `production_sell`, `breakdown_purchase`,
  `breakdown_sell` — excluded from `App\Support\TransactionTypes`.

**Kept deliberately** — these only *look* manufacturing-related:
- `purchase_lines.mfg_date` — a stock lot's *manufacturing date*, paired with `exp_date`
  for expiry tracking.
- `business.expiry_type = add_manufacturing` — expiry measured from that date.

Verified: no table matches `/mfg|break_down|recipe|manufact/i`.

---

## 3. Modules absent from the source repository

`modules_statuses.json` enabled 9 modules whose code does not exist in the documented
repo, so they cannot be rebuilt: **Connector, Crm, Ecommerce, FieldForce, Project, Repair,
Spreadsheet, Woocommerce, AiAssistance**. Dropped references:

| Dropped | Was in |
|---|---|
| `Modules\Crm\Entities\CrmContact` | `User::contact()` |
| `Modules\Ecommerce\Entities\EcomApiSetting` | `EcomApi` middleware |
| `HmsBookingLine`, `HmsBookingExtra` | `Transaction` relations (HMS never existed) |
| `repair.*`, `client.clients.*` permissions | sidebar + reports |
| `pos:WooCommerceSyncOrder` | scheduler |

`users.crm_contact_id` is kept (documented schema) with no relation pointing at it.

---

## 4. Tables referenced by code with no migration in the source

Per §15.1 of the documentation and your decision #1, these are **not created**:

```
client_types · client_relationships · professions · titles · genders
marital_statuses · kyc_identification · income_categories · contact_restrictions
bank_details · payment_accounts · payment_term_types · work_details · work_status
```

Related dead code, also not rebuilt: `app/PaymentAccount.php` (points at the missing
`payment_accounts`) and `PaymentAccountController` (absent from the source repo). The
documented payment-account feature is served by `AccountController` + the `accounts` table.

---

## 5. Arabic / RTL implementation

Arabic is the primary locale, not a translation layer.

**Built:**
- `<html lang dir>` driven by `config('constants.langs_rtl')` (`ar`, `ps`) via the
  `Language` middleware, which shares `$text_direction` / `$is_rtl` with every view.
- **Logical CSS properties only** — `ms/me`, `ps/pe`, `start/end`, `text-start/text-end`.
  No `ml-*`, `mr-*`, `text-left`, `text-right` anywhere in Blade. One stylesheet serves
  both directions; **no separate `rtl.css` build**.
- **Cairo** font (Arabic + Latin in one family) so metrics don't shift across locales.
  Self-hosted through Vite alongside **JetBrains Mono**, which supplies the tabular
  figures every money and quantity cell relies on. No external font request, so the UI
  renders identically offline — which the POS terminal depends on.
- **Numbers stay LTR inside RTL text** — `.cell-numeric`, `.input-numeric`, `.stat-value`
  and `.force-ltr` set `direction: ltr` with tabular figures, so money and SKUs remain
  readable and decimal points align.
- **Arabic-Indic digit input** — `FormattingService::normaliseDigits()` server-side and
  `normaliseArabicDigits()` client-side, so `١٢٣٤` typed on an Arabic keyboard parses as
  1234 instead of 0. (The original would have silently read it as zero.)
- **Directional icons mirrored** via `.icon-directional` (`rtl:-scale-x-100`).
- **Select chevron flips side** in RTL.
- **Time axes stay LTR** — the dashboard sparkline is explicitly `dir="ltr"` because a
  time series always runs left→right.
- **Print styles** are direction-agnostic, including a 72 mm thermal-receipt rule.
- `lang/ar/lang_v1.php` and `lang/en/lang_v1.php` — 471 keys each, parity verified by
  `scripts/add-lang-keys.php`. Arabic uses proper plural forms (`invoice_count` has the
  4 Arabic plural categories).
- **No screen may render a raw key.** `ScreensRenderTest` greps every 200 response for
  `/lang_v1\.[a-z0-9_.]+/` and fails on a match, because a missing key renders as its own
  name — invisible to a status check, glaring to a user.

Verified by test: `ApplicationSmokeTest::a_registered_owner_can_sign_in_and_reach_the_dashboard`
asserts `dir="rtl"` and `lang="ar"` render for an Arabic user.

---

## 6. Schema deviations from the literal documentation

| # | Doc says | Built | Why |
|---|---|---|---|
| 1 | 308 incremental migrations | 18 final-state migrations | The doc states the 308 are cumulative history and presents the final schema as authoritative (§3.1); §14 recommends rebuilding from it. Table/column names byte-identical. |
| 2 | `password_resets` | `password_reset_tokens` | Laravel 13 convention. |
| 3 | `type/status/payment_status`, `contacts.type`, `payments.method` as `enum` | `varchar(191)` + index | §3.3 documents these already outgrew their enums. |
| 4 | `products.type` = `enum('single','variable')` | `varchar(191)` | §3.3 lists `combo` as a real value; the enum was stale. |
| 5 | `products.barcode_type` (two conflicting definitions) | `varchar(191)` default `C128` | The history contains a raw `DB::statement` rewriting it; §3.3 lists a third set. |
| 6 | Auto-generated index names | Explicit `tslpl_*`, `euad_*` | MySQL caps identifiers at 64 chars; generated names exceeded it. |
| 7 | `payment_terms` has only `id, due_date, payment_term` | Added `purchase_transaction_id` + FK + timestamps | The documented `PaymentTerm` model declares that key and a `transaction()` relation, so the column must exist. The §3.4 listing was incomplete. |
| 8 | `business.p_exchange_rate` = `decimal(5,3)` | `decimal(22,4)` | `decimal(5,3)` caps at 99.999 — cannot hold real rates. |
| 9 | `transactions.exchange_rate` = `decimal(8,3)` | `decimal(22,4)` | Your decision #6. |
| 10 | `tax_rates.amount`, `customer_groups.amount`, `default_profit_percent` as `float` | `decimal` | Floats are unsafe for values feeding invoice totals. |
| 11 | `Schema::defaultStringLength(191)` | Applied | Kept for index headroom; harmless on MySQL 8. |

---

## 7. Architecture

| Area | Original | Rebuild | Reason |
|---|---|---|---|
| Models | `app/*.php` (Laravel 5 style) | `app/Models/*` | Laravel 13 convention; `$table` declared wherever the name is unconventional. |
| Business logic | `app/Utils/*` — 11 God-classes (`TransactionUtil` = 7,185 lines / 79 methods) | `app/Services/*` by domain | Testability. Documented method names preserved. |
| Modules | `nwidart/laravel-modules` v9 | `app/Modules/<Name>/` domain folders | nwidart v9 doesn't support Laravel 13; §14.2 recommends exactly this. |
| Permissions | spatie v5 | spatie **v8.3** | Only v7+ supports Laravel 12/13. Custom `roles.business_id` + `is_default` and the `Admin#<id>` convention preserved exactly. |
| Frontend | jQuery + AdminLTE 2 + Bootstrap 3 | Tailwind 4 + vanilla JS | Both originals are EOL; you asked for best design. |
| Build | Laravel Mix | Vite 8 | Already scaffolded; Mix deprecated. |
| Tenancy | Global scope reading `session()` directly | `App\Support\Tenancy` + scope | **Bug fix:** the original silently disabled tenancy in console/queue contexts. Now the tenant can be bound explicitly and HTTP **fails closed** (`1=0`) when unbound. |
| Background work | `sync` queue, synchronous notifications | `database` queue, queued notifications | §15.3 flags this as a defect. |
| `AppServiceProvider` | `memory_limit=-1`, `set_time_limit(0)`, `error_reporting(0)` per request | Not reproduced | §15.3 flags all three as bugs. |
| 30 × `fix:*`/`stock:*` repair commands | present | Not reproduced | They exist to paper over integrity bugs. Fixed at the root — see §7.1. |

### 7.1 Stock integrity — the root fix

The source ships ~30 repair commands because the stock cache and the FIFO map were
updated in separate code paths and drifted. In the rebuild:

- `StockService` is the **only** thing that moves stock, and every public method updates
  the cache *and* the FIFO map together.
- Every mutating method calls `assertInTransaction()` and **throws** outside a DB
  transaction — a partial movement cannot be committed.
- Lot rows are read `lockForUpdate()`, so two concurrent POS sales of the last unit
  serialise instead of both succeeding.
- Overselling is **reported** (`shortfall`) rather than silently absorbed.
- `reconcile()` recomputes cache-vs-FIFO on demand and returns the difference.
- Editing a purchase below the quantity already issued from it **throws** instead of
  corrupting the lot.

All of this is covered by tests (§9). One caveat the design makes explicit rather than hides:
`adjustCachedQuantity()` is a **separate call the caller has to make** — `consume()`,
`release()` and `reduceLotQuantity()` deliberately touch only the FIFO map and the lot
counters. That is the one seam where the two records can still drift, and it is why every
document that moves stock (item 6's three included) is tested by asserting
`reconcile()['difference'] === 0.0` after the movement rather than by inspecting either record
alone.

---

## 8. Autonomous decisions (your instruction #8)

| Decision | Rationale |
|---|---|
| **Tailwind `@utility` for composable primitives** | Tailwind 4 refuses to `@apply` a class defined in the same `@layer components` — the build failed with `Cannot apply unknown utility class btn`. `@utility` registers them so variants can compose. Which ten, and why only ten, is in §8.1. |
| **No JS framework** | The UI is server-rendered Blade; sidebar/dropdowns/confirm/connectivity need ~200 lines. Vue or Alpine would be dead weight. |
| **`SimpleCrudController` abstract base** | The source has ~15 near-identical 250-line settings controllers. Subclasses declare only what differs. |
| **Sidebar entries guarded by `Route::has()`** | Navigation degrades gracefully while routes are added incrementally, instead of 500-ing. |
| **Real MySQL for tests, not SQLite** | The code relies on `lockForUpdate` (a no-op in SQLite) and MySQL decimal semantics. Testing on SQLite would prove nothing about concurrency. `.env.testing` + `souqly_test`. |
| **Print-agent auth via HMAC location token** | The LAN print agent can't hold a user session. `location_id:hmac_sha256(location, APP_KEY)`, compared with `hash_equals`. Without this, anyone could enumerate other shops' print jobs. |
| **Paymob callbacks verified by HMAC-SHA512** | Paymob's documented field order is spelled out explicitly. Any payload failing verification is **rejected**, so a forged "success" cannot settle an invoice. |
| **Print jobs claimed on fetch; stale `printing` jobs requeued after 1 h** | Prevents double-printing when two agents poll, and prevents losing a job when an agent dies mid-print. |
| **`/api/ping` unauthenticated and dependency-free** | It must answer even when the session store is down, or the offline POS can't tell "no network" from "server broken". |
| **Client probes `/api/ping` rather than trusting `navigator.onLine`** | `navigator.onLine` reports link state, not reachability — it lies behind captive portals. |
| **`payment_status` derived, never assigned** | Single `refreshPaymentStatus()` recomputes it from payment rows with a 0.0001 tolerance, so a fully-settled invoice never shows a fractional due. |
| **Contact-due settlement allocates oldest-first, banks the excess** | Matches the documented parent/child payment structure; overpayment becomes advance balance instead of being lost. |
| **Payment terms rejected when they exceed 100 %** | The source allowed it, producing schedules that could never be satisfied. |
| **Pusher channels scoped per tenant, and location channels double-checked** | `location.{id}` verifies both the `location.{id}` permission *and* that the location belongs to the user's business — otherwise a permission id collision across tenants would leak a live order feed. |
| **`spatie/laravel-model-flags` not installed** | Its only contribution was the `flags` table, which our own migration creates. |
| **`maatwebsite/excel` v3 + `ext-zip`** | Real `.xlsx` import/export as documented, rather than downgrading to CSV. |
| **EGP first in the currency seeder; 55 currencies** | Egypt is the primary market. |
| **Admin bypass moved into `Gate::before()`** (`AppServiceProvider::registerAdminBypass`) | The bypass originally lived only in `Controller::permit()`/`allows()`. But `BusinessLocation::permittedLocations()` calls `$user->can('access_all_locations')` **directly**, and the Admin role holds no explicit permissions — so for an admin it resolved to *zero* permitted locations and the `permittedLocations()` query scope silently returned nothing. Every purchase/sale/transfer/report lookup 404-ed for the system's primary user. Fixing it at the gate makes `can()` consistent in controllers, models, Blade `@can` and policies alike. Caught by `ScreensRenderTest`. |
| **Default `admin` account shipped as a seeder, not a migration** | Credentials must never be part of the schema. `AdminUserSeeder` is idempotent and provisions a whole tenant through `BusinessService::register()`, so the dev account exercises exactly the same code path as a real sign-up. Credentials at the top of this file. |
| **A dev account with a real password, not a trivial one** | Set via `SEED_ADMIN_PASSWORD` in `.env`, never committed. The account is unrestricted, so a guessable password would be a live hole the moment the build is reachable from anything but localhost — and a committed one would be published by the first push. The seeder throws when the variable is unset rather than falling back to a default. Rotate before any deployment. |

### 8.1 UI system (v2)

> Revised by **design system v2.1** — palette and section decisions in **§11**. The
> decisions in this table all survived the revision; only the colour values and the
> surface language (border → ring) changed.

Every screen was rebuilt against one set of named primitives in `resources/css/app.css`.
The rule the whole system rests on: **a screen composes primitives, it does not invent
utility stacks.** If a screen needs something new, the primitive is added to `app.css`
with a comment saying why — so the next screen inherits it instead of re-deriving it.

| Decision | Rationale |
|---|---|
| **Only 10 `@utility` declarations; everything else in `@layer components`** | Tailwind 4 can only `@apply` a *registered* utility, so the ten (`btn`, `badge`, `alert`, `input`, `card`, `nav-link`, `tile`, `link`, `avatar`, `page-link`) are exactly the ones other classes need to build on. Keeping the list minimal is what stops `@utility` becoming a second, unordered stylesheet. |
| **Four button ranks and only four** | `.btn-accent` commits money (at most one per screen), `.btn-primary` is the page's main action, `.btn-secondary` everything else, `.btn-danger` destroys. A fifth rank would mean two buttons competing to look most important, which is how a screen stops being readable at a glance. |
| **Icon-only row actions** (`.btn-icon`) | Three text buttons per table row pushed the numeric columns off-screen at 1366 px. Every icon carries `title` **and** `aria-label`, so nothing is lost to a screen reader. |
| **Page container capped at `max-w-[96rem]`, with a `full_bleed` escape hatch** | Tables stretched to 2560 px are unreadable — the eye loses the row. Screens that genuinely need the width (POS, label sheets) declare `@section('full_bleed')`. |
| **Framework paginator replaced with a published view** | Laravel's default ships Tailwind 3 colours that exist nowhere in this palette, so pagination was the one off-brand element on 20 screens. `.pagination` carries its own top border and padding, so `{{ $records->links() }}` needs no wrapper. |
| **Page title split: `<x-page-head>` for the action row, `@section('page_title')` for the header** | The two were one block, which meant every screen chose its own vertical rhythm for the same information. |
| **`.form-actions` (sticky, page level) vs `.card-actions` (inside a panel)** | A long form's save button must stay reachable without scrolling; a panel's own actions must not. Two names, because using one for both put a sticky bar inside a card on three screens. |
| **`accent-color` for checkboxes rather than `@tailwindcss/forms`** | The plugin restyles every control in the app to fix one; `accent-color` fixes the one. |
| **Global formatting helpers via `composer.json` `autoload.files`** | `format_currency()`, `or_dash()` and friends are called from Blade in hundreds of places. A facade or an injected service in every view would be ceremony around a pure function. |
| **`or_dash()` + `.cell-none` for empty cells** | An empty `<td>` reads as a rendering fault. A muted em-dash reads as "nothing here", which is the truth. |
| **`<x-attr-list>` + the `.attr-*` family** | Every `show` screen displays label/value pairs. Nine screens had nine different two-column grids before this. |
| **`<template>`-cloned line rows for multi-line forms** | Purchase lines, sell lines and POS cart rows are all built by cloning a `<template>` with an `__i__` index placeholder. `<template>` contents are inert, so the placeholder is never a form-associated field — no server-side filtering needed. |
| **`welcome.blade.php` deleted** | It was the only consumer of a whole set of marketing utilities: removing it cut 23 kB from the CSS bundle. |
| **The untranslated-key guard lives in the route walk, not in per-screen tests** | See §5. It costs one `preg_match_all` per screen and catches the single most common defect when a screen is written before its keys are added. |

### 8.2 Sales / POS (item 4)

| Decision | Rationale |
|---|---|
| **`SellPosController` composes `SellService`; it is not a `SellController` subclass** | It shares no listing, no edit window and no multi-panel form. Inheriting all of that to override two methods would misrepresent the relationship. (`SalesOrderController` *is* a subclass, because it genuinely is the same document with a different type and permission prefix.) |
| **`ShipmentController` is read-only; the one write lives on `sells.updateShipping`** | A shipment is a sale seen through its shipping columns, not a second document. Giving it a create screen would imply otherwise. |
| **Shipments listed oldest-first, and only rows with a shipping status** | It is a work queue: the oldest undelivered order is the most urgent. Because that is the opposite of every other listing in the app, the screen says so out loud (`shipment_queue_hint`) rather than looking like a sort bug. |
| **No per-line discount column on any sell form** | `SellService::recalculateTotals()` applies discount at document level only. A column the service ignores is a lie in the UI. |
| **The live totals panel mirrors `recalculateTotals()` in the same order** | Subtotal → document discount → order tax. Any other order produces a figure that changes on save, which destroys trust in the screen. |
| **`price_group_id` threaded through `products.list`** | Otherwise a customer on a price group saw list prices while the saved sale used group prices. |
| **Sales-order line import offered on create only** | Importing lines into an order that has already been partly invoiced would silently double-count fulfilment. |
| **`statuses` + `canUpdate` resolved in `SellController::show()`** | The ability is `sell.update` for a sale and `so.update` for an order; the view should not have to re-derive which document it is looking at. |
| **A `<form>` wraps `<x-panel>`, never the reverse** | The component renders its `footer` slot after the default slot, so a form opened inside the panel closes before the submit button. Cost an hour once; written down so it costs nobody else. |
| **Five sell-side fixtures in `ScreensRenderTest`, not one** | The sell side splits its listings by state (final / draft / quotation / order / shipped). One fixture leaves four of five listings rendering their empty state — which passes the walk while covering none of the row markup. |

### 8.3 The POS terminal — `resources/views/pos/create.blade.php`

Designed to your brief: calm light surfaces, two zones, one unmistakable commit button,
nothing on screen the cashier does not need. It is the most-used screen in the product,
so its decisions are recorded individually.

**Layout and colour**

| Decision | Rationale |
|---|---|
| **The only view that declares `@section('full_bleed')`** | Capped at 96 rem the product grid is four tiles wide with empty space either side. The terminal is the one screen that wants the whole monitor. |
| **Two zones and nothing else** — `.pos-shell` = product grid + cart (`19rem`, `24rem` from `lg`) | Requirement 2 of the brief. Anything that is not choosing a product or reading the basket is behind a toggle. |
| **The shell is pinned to the viewport — `height: calc(100dvh - var(--pos-offset))` — and each zone scrolls inside itself** | Replaced the original model (both zones `position: sticky` with a matched `max-height: calc(100vh - 6.5rem)`), which had a real defect: the cap assumed each zone began at its sticky offset, when at scroll 0 the shell begins ~160 px lower — so the total and the pay button sat that far below the fold until the cashier scrolled. Pinning the shell instead means the page never scrolls at all, and nothing can be pushed off-screen by a long product list. `--pos-offset` is measured at runtime because the register bar above wraps to two or three rows on a narrow window; the CSS literal covers the first paint. |
| **Side by side from `48rem`, not `64rem`** | Stacked, the product grid sits above the cart and buries it — the reported symptom. A 1366 px laptop at Windows' 150 % display scaling reports 910 CSS px, which is *below* `lg`, so the two-column layout the terminal was drawn for silently never engaged on ordinary hardware. Stacked is now the phone layout only. In RTL the cart lands on the left, which is where it was asked for. |
| **The cart never grows and always yields** — `flex: 0 1 auto`, `max-height: 55%`, and `min-height: 0` on the rows and on the empty state | Inside a capped shell something has to give way, and it must not be the footer: the total and the button that takes the money are the last things allowed to be clipped. So the rows scroll, the empty-state artwork gets cut before the total does, and the extras panel scrolls rather than pushing the footer out when opened on a short window. |
| **The cart column is one wrapper with four parts in a fixed order** — counter, scrolling rows, total, pinned button — and a test asserts it | `.pos-shell` is a two-column grid, so anything that escapes `.pos-cart` becomes a grid item in its own right and auto-placement drops it in the next free cell. A single missing wrapper therefore does not degrade the layout, it scatters it: counter in one cell, rows in another, total below. That is not hypothetical — it shipped, from one malformed Blade comment terminator, and is written up in §9.2 along with the two guards added. The order is the column's whole argument: a total placed above the lines it totals reads as a price. |
| **Exactly one added tone on the whole screen** — `bg-brand-50/60` on the cart header | White surfaces and slate text, per requirement 1. One 6 %-opacity tint is enough to separate the zones; a second colour would start competing with the pay button. |
| **`.btn-accent` (`accent-700` #005a54) for *Finalize sale*, at `.btn-lg btn-block`** | The documented "commits money, one per screen" rank, and deliberately **not red**: red on a button pressed two hundred times a day reads as a warning and stops meaning anything (requirement 3). Under v2 this rank was a warm copper; v2.1 replaced the second hue with a deeper register of the brand — the rank is now carried by depth and elevation, not by colour. See §11. |
| **Every target ≥ 36 px; tiles 152 × 96** | Requirement 7 — sized for a thumb on a tablet, not a mouse. Two primitives were added to reach it: `.input-lg` for the search field (a size modifier must be the last word in the cascade to win, so it sits after `.input-search`), and a `min-height` on `.stepper`, which without one is only as tall as its input's line box — about 24 px. |

**Behaviour**

| Decision | Rationale |
|---|---|
| **Label-less selects in the register bar** (location / customer / price group, each with an `aria-label`) | A label row pushes the product grid ~20 px further down the screen on every sale of the day. The three fields are self-evident from their contents. |
| **Discount and order tax behind a toggle; notes inside the payment dialog** | Requirement 4. The breakdown row appears only when a discount or tax is actually non-zero, so the normal sale shows one number: the total. Notes are the one field nobody fills in during a rush, so they sit where the sale is already paused. |
| **Out-of-stock tiles flagged (`.product-tile-out`), not disabled** | Plenty of shops sell the last unit while the count catches up. Refusing the sale at the tile would make that a policy this screen invented. |
| **One payment line, not a split-payment table** | Split payments at a counter are clutter for a case that is rare. The sale is reachable from the banner afterwards, where a second payment can be added. |
| **The visible *tendered* field has no `name`; the hidden `payments[0][amount]` is clamped to `min(tendered, total)`** | Handing over 500 for a 320 sale is 320 taken and 180 back — not a 180 customer credit. The change due is shown, never posted. |
| **The payment dialog lives inside the `<form>`** | Its fields post without needing a `form=` attribute on each one. It is the first consumer of the `.modal-backdrop` / `.modal-panel` primitives, widened to `max-w-2xl` and capped at `90vh` so a landscape tablet scrolls the dialog rather than losing its footer. |
| **Keyboard: F2 focuses search, F4 opens payment, Esc closes, Enter on an exact SKU adds the item** | That last one is the barcode-scanner path — a scanner is a keyboard that types fast and presses Enter. |
| **Enter is inert on every other input outside the dialog** | Implicit form submission from the discount field or a quantity stepper would post the basket with nobody having seen the total. Buttons and the notes textarea are excluded, so keyboard use still works. |
| **Submit disables the finalize button** | On a touch screen a double tap is otherwise two sales. |
| **DOM-as-state: a monotonic index counter, no re-render** | Re-rendering the cart on every keystroke would move focus out of the quantity field being typed into. |
| **The basket survives a rejected sale** | `store()` returns `back()->withInput()`, so each row carries a hidden `lines[__i__][name]`. `$request->validate()` returns only validated keys, so the label reaches `old()` but never `SellService` — the cart is rebuilt on load with no second lookup. |
| **`recalc()` implements the same arithmetic as `SellService::recalculateTotals()`, in the same order** | The figure the customer is asked for must be the figure that gets saved. No line tax, shipping or round-off, because the terminal does not offer them — a sale made here has none. Both sides carry a comment saying that if one changes, so must the other. |
| **Redirects to an empty terminal, not to the invoice** | The next customer is already at the counter. The receipt is reachable from the status banner, so the sale is not lost — it just is not what the screen becomes. |

### 8.4 Stock (item 6)

Three documents that move stock without selling it, and the first item where the *screens* can
produce a defect that no error and no red figure would ever show: a cached quantity that
disagrees with the FIFO map. So the controllers here are deliberately the thinnest in the
application — every rule about what may happen to stock lives in the three services, which are
the only code that touches the map. What the controllers own is vocabulary: filters, totals,
the two adjustment kinds, the two transfer states.

**Shape of the three documents**

| Decision | Rationale |
|---|---|
| **A transfer has no `edit` and no `update`; `stock_transfer.update` guards *receiving* instead** | Editing a document whose goods are on a van would have to decide what the van is now carrying. The app declines to invent an answer: a wrong transfer is deleted and re-entered while it is still in transit. That makes receiving the one legitimate change to an existing transfer, so it is what the update permission protects. |
| **The transfer listing shows out-legs only** | A transfer is two `transactions` rows, but it is one event to the person who arranged the van. Listing both halves would double every row and invite somebody to delete the wrong one. The destination and its status are read through `transfer_child`. |
| **Opening stock has no `create` and no `store` — `opening-stock.edit` *is* the create screen** | "What this product's opening quantity is at this shop" is a single statement that has either been made or not. A create/edit split would ask the user which of those they are doing, when the screen can simply open and show what is currently on file. |
| **Its route parameter is `{productId}`, not `{id}`** | It addresses a product, not a document — the document may not exist yet. Naming it `{id}` would have been a lie that also fooled `ScreensRenderTest`'s parameter resolver into passing a transaction id. |
| **Location travels in the query string on opening stock, in the path on nothing** | It is a key to the document, but it is also the thing the user switches while working — checking one product across three shops. In the path that is a redirect per switch and three URLs for what feels like one screen. |
| **An adjustment edit reverses the whole document and rewrites it; opening stock edits its lot in place** | Opposite choices for opposite reasons. An adjustment *line* only points at lots, so reversing it is cheap and exact. An opening-stock line **is** a lot — destroying it would orphan every sale that had consumed from it, so it is edited in place and the shrink guard is what keeps the edit honest. |
| **`updateStatus` (receive) is POST, not GET** | It books stock at the destination. Same rule as `sells.convert`: anything a link-prefetcher or a browser history replay could fire must not move stock. |
| **Adjustment totals are never netted** | `listTotals()` returns loss, recovered, net *and* abnormal separately. A month where breakages doubled but insurance paid for them is a different month from one where nothing broke, and a single net figure cannot tell them apart. |
| **One location filter matches either leg of a transfer** | "Which shop was it to or from?" is one question to a user. A transfer between two shops is relevant to both, so one `location_id` filter checks `location_id` *or* `transfer_child.location_id` rather than making the user pick a direction first. |
| **`opening-stock.index` lists only `enable_stock = 1` products** | A service or an untracked product has no opening position to state. Listing them and refusing on save would be a screen that offers work it cannot do. |
| **Its summary figures come from one grouped query, not one per row** | 25 documents-with-lines per page is the difference between one query and fifty, and this listing needs only a quantity and a value per product. |

**Screens and behaviour**

| Decision | Rationale |
|---|---|
| **No money column anywhere in the adjustment line editor** | What a write-off is *worth* is the FIFO cost of the specific units it takes, and that is not knowable until the document saves and the lots are allocated. So the editor shows the two facts a person can check while typing — what is on the shelf, and how much is gone — and the valuation appears on the document screen, where it is a fact rather than a guess. |
| **"Available" on an existing adjustment line means current stock *plus what this line already took*** | Those units are already written off, so live stock excludes them. `available: 0` beside `quantity: 3` reads as a bug; the number the row needs is what this document may take. |
| **Changing location blanks every "available" figure on screen rather than leaving it** | Each one was read at the old location. A stale number here is worse than no number, because it is the single figure the user is trusting. The rows are kept — the products are usually still the right ones. |
| **A transfer's product lookup is scoped to the *source* location** | The quantities it returns are the ones the transfer is bounded by. A product the destination happens to stock is irrelevant if the source has none of it. |
| **Same shop on both sides is caught three times** | `different:location_id` on the server, `setCustomValidity()` on the destination select, and the source-change handler clearing a now-invalid destination. The server rule is the one that matters; the other two exist so the mistake costs a keystroke instead of a round trip and a lost form. |
| **A "how this works" panel on both create forms** | "Why can't I add stock here?" is the first question the adjustment screen gets, and every rule listed (decrease only, never past zero, valued at cost; two documents, in-transit, at cost, no edit) is one the service will otherwise teach by refusing to save. Answering in place is cheaper than answering in support. |
| **Freight is shown beside the goods value, never inside a unit price** | A transfer's unit costs stay what the goods were bought for — that is what makes the destination's stock still reconcile against its lots. Shipping is a cost of the move, so `transfer_freight_hint` says so on both the form and the document. |
| **The `in_transit` and `abnormal` stats are toned only when non-zero** | Both are figures that need acting on rather than reading — stock that has left a shop and been confirmed nowhere, and losses somebody should look into. A permanently-warning tile is wallpaper; one that lights up is a reminder. |
| **`stock_transfer/show` leads with the route, full width, above everything else** | A transfer's identity is which two shops it is between, and the in-transit case needs the direction of travel to be unmissable. Both halves are then listed again with their own statuses, because "where is the other document" is the first question anyone reconciling a transfer asks — and a missing child gets a danger badge rather than a blank. |
| **The in-transit banner states the invariant in words** | The goods are counted at neither shop, and somebody has to confirm arrival before the destination can sell them. That is surprising enough to be worth saying on the document rather than leaving it to be discovered as a missing quantity. |
| **Opening stock's location filter has no "All" option and submits on change** | Every figure on the screen is "at this shop", and there is no such thing as opening stock without one. Switching shop is the main thing done here, so asking for a click on *Apply* as well would be a step for nothing. |
| **A `recorded=yes/no` filter on the opening-stock listing** | The actual job on that screen is finding the products whose opening position has *not* been stated yet. Without the filter that is a manual scan of the whole catalogue. |
| **No permitted location renders an empty state, not an empty table** | Nothing on that screen can be true without one, and an empty product table reads as "no products" — a different and wrong message. |

---

## 9. Verification — what is actually proven

```bash
php artisan migrate:fresh --seed   # 19 migrations, 131 tables, 55 currencies, 181 permissions
php artisan test                   # 71 tests, 336 assertions — all 71 passing
php scripts/verify-models.php      # 109 models, 323 relations, 460 casts — clean
php scripts/add-lang-keys.php      # 906 ar / 906 en — parity OK, zero orphans either way
npm run build                      # 121.26 KB CSS (gzip 14.92 KB), builds clean
php artisan route:list             # all routes resolve

php artisan db:seed --class=DemoDataSeeder   # demo catalogue, idempotent — see below
```

**`DemoDataSeeder` — a catalogue big enough to actually use the screens.** Deliberately not
registered in `DatabaseSeeder`: the suite runs `migrate:fresh --seed` against `souqly_test`,
and 49 products would break every count-based assertion in it. Run it by name, against the
development database. It seeds 10 brands, 27 categories (8 parents + 19 children), 4 units,
4 variation templates, 4 suppliers + 4 customers, and **49 products — 41 `single`, 5
`variable` (18 variations between them) and 3 `combo`, 62 sellable variations** — then gives
every stock-enabled product an opening-stock document and adds a second FIFO lot at 1.08×
cost to every fourth single product, so the costing code has more than one lot to choose
from. Combos carry `enable_stock = 0` on purpose: their availability derives from their
components. It is idempotent — a second run reports "already seeded" and inserts nothing.

**The suite is fully green as of 2026-08-24.** The failure recorded here previously —
`ScreensRenderTest` reporting 19 screens as HTTP 500/404 because item 5's routes were
registered before its views existed — is resolved: those views were written, and the walk now
renders all of them. The guard did exactly what it was built to do.

**One trap worth knowing, because it costs an hour to diagnose:** the suites use
`DatabaseTransactions`, not `RefreshDatabase`, so they run against the **persistent**
`souqly_test` database and never migrate it themselves. After any migration change you must
run `php artisan migrate:fresh --seed --env=testing`, or tests fail with a missing column
that plainly exists in the migration file. This is how the `transaction_payment_id` column on
`cash_register_transactions` (added when payments were wired to the drawer) presented itself:
`ProcureToPayCycleTest::deleting_a_sale_returns_its_stock` failed on a column the schema
defines. The code was correct; the test database was stale.

| Suite | Covers |
|---|---|
| `FifoStockTest` (8) | Oldest-lot-first consumption, weighted cost, cache-vs-FIFO agreement, overselling shortfall, release on edit/delete, partial return crediting newest lot first, explicit lot override, adjustments tracked separately, purchase-shrink guard |
| `StockMovementsTest` (19) | The three documents that move stock without selling it. Adjustments: write-off priced at what those units actually cost, refusal above what the location holds, delete returning units to their lots, edit reversing the whole document before rewriting it. Transfers: cost carried to the destination with freight kept outside unit price, **goods in transit counted at neither shop**, receive booking stock in, double-receive refused, same-location refused, source overdraw refused, in-transit delete crediting the source *only*, received delete unwinding both halves, delete refused once the destination has sold. Opening stock: a sellable lot, restatement editing the same lot in place, refusal to cut below what has been sold, zeroing withdrawing the document, per-location statement. Plus all three refusing products that are not stock-tracked |
| `ProcureToPayCycleTest` (10) | Received vs pending purchase, full purchase→sale→payment→return cycle, purchase return capped at remaining lot, PO fulfilment `ordered→partial→completed`, payment terms + >100 % rejection, contact-due allocation with advance banking, credit-limit breach, pre-sale shortfall detection, sale deletion restoring stock |
| `StockTransactionGuardTest` (6) | Every stock/payment mutation refuses to run outside a DB transaction |
| `ApplicationSmokeTest` (10) | Login page, guest redirect, sign-in → dashboard **rendering RTL Arabic**, default tenant resources, brand creation stamped with tenant, **cross-tenant isolation**, permission refusal, `allow_login=0` block, `/api/ping`, print-agent token rejection |
| `UsernameLoginTest` (5) | Username-based sign-in against the seeded admin, whose password comes from `SEED_ADMIN_PASSWORD` (§12.2) |
| `ScreensRenderTest` (2) | Every named GET route rendered as an admin — **109 of them**, up from 100 as item 6 landed — asserting no 4xx/5xx, no raw `lang_v1.*` key in any response body, and **balanced `<div>` markup** (§9.2). 20 routes are in its `SKIP` list, each with a stated reason (JSON endpoints, file downloads, guest-only). **No item-5 or item-6 route is skipped.** The second test asserts the POS cart is one connected column, through the DOM rather than by substring — see §9.2 |
| `CashDrawerTest` (11) | What a shift's drawer knows and in which direction: cash to a supplier and an expense at the till as `payout`, a purchase return netting payouts down instead of counting as takings, a contact-due settlement writing one parent row and no allocation rows, a corrected payment updated in place, a card payout stated without moving `cash_in_hand`, plus the four original movements — the `initial/sell/refund` sequence, change handed back, advance balances staying out, no-register-open writing nothing and not being acquired by the next shift, and deletion removing its row (§12.1) |

### 9.1 Why the 100-screen walk let a 500 through, and what changed

The products screen shipped reading `$product->variations` in its row loop with `variations`
absent from `ProductController@index`'s `with()`. The walk rendered it green; it threw
`LazyLoadingViolationException` the moment a real catalogue was seeded. Two independent
causes, both now fixed:

1. **Strict mode was off in the one environment that could have caught it.**
   `Model::preventLazyLoading($this->app->isLocal())` is false under `APP_ENV=testing`, so
   no test has ever had the guard on. Now `! $this->app->isProduction()` — on everywhere
   except production, where a missing eager load should cost queries, not a page.
2. **One fixture row per entity cannot trip the guard even when it is on.**
   `Builder::hydrate()` arms detection per model instance and only for result sets of more
   than one row (`if (count($items) > 1)`), so a single-row index is permanently exempt.
   `ScreensRenderTest::seedListingDuplicates()` now creates a *second* row for every entity
   that has a listing screen — a handful of inserts that turn the walk into a real
   missing-eager-load detector for every screen, including ones written later.

Turning the guard on immediately found a second instance of the same bug: `purchase/show`
renders `$term->amount` per instalment, and that accessor reads back through
`PaymentTerm::transaction` — so any purchase with a two-part payment schedule would have
500ed on that screen. Fixed at the relation with `Transaction::terms()->chaperone('transaction')`,
which hands each child the parent it was loaded from at no query cost.

### 9.2 A Blade comment ate a `<div>`, and every guard we had said the page was fine

Reported by you on 2026-08-24: the POS cart's contents were appearing in the middle of the
screen under the products, instead of inside the cart column. **The cause was one missing pair
of dashes.** A banner comment in `pos/create.blade.php` was terminated
`--------------------------------------------------------------- }}` — a space between the
dashes and the braces, so it is not `--}}`. Blade's comment pattern is
`/\{\{--(.*?)--\}\}/s`: non-greedy, but unanchored and multi-line, so it simply kept scanning
to the **next real `--}}`** three lines later and deleted everything between — including
`<div class="pos-cart">`, the line that made the cart a single column.

The closing `</div>` survived. `.pos-shell` is a two-column grid, so the cart's four parts
became grid items in their own right and auto-placement scattered them across free cells: the
items counter in one, the rows in another, the total below, the pay button somewhere else
again. Exactly what the screenshot showed.

**What makes this worth a section is how quiet it was.** The page returned 200. Every
translation key resolved. The CSS was correct and freshly built. `git status` was clean. The
compiled view in `storage/framework/views` was *faithful* — it really did lack the wrapper —
so the early suspicion of a stale cache was wrong, and `view:clear` changed nothing. Two of my
own diagnostic checks also lied before the real evidence appeared: a PowerShell-quoted
`php artisan tinker --execute` where `\"` was consumed by the shell rather than reaching PHP,
so the probe searched for a literal backslash and reported the wrapper missing from the
*source* too; and a `Grep` context line that rendered a `//` JS comment as `\`, which briefly
looked like a second bug in the same file. Both were artifacts of the tooling, not facts about
the code. The lesson is narrow and practical: when a check disagrees with a file you have read,
suspect the check.

**Two guards added, and both verified by re-breaking the view deliberately** — a guard that has
never failed is not a guard:

1. **Balanced `<div>` markup on every screen**, inside the existing route walk. Unbalanced
   markup is how a layout breaks without anything erroring, and this is generic: it would
   catch any swallowed or orphaned wrapper on any of the 109 screens, not just this one. With
   the typo reintroduced it reported `pos.create → 67 <div> vs 68 </div> (-1)`. `<script>`
   blocks are stripped before counting, because a `'<div>'` inside a JS string is not markup
   and counting it would make the guard cry wolf on every screen that builds HTML in
   JavaScript.
2. **`the_pos_cart_is_one_connected_column`**, which asserts through `DOMDocument` + XPath that
   `#cart-count`, `#cart-rows`, `#cart-total` and `#open-payment` are all *descendants of*
   `.pos-cart` and appear in that order. Containment is asserted through the DOM and not by
   searching the HTML on purpose: this bug does not remove the four parts, it moves them out of
   their parent, and a substring check cannot see the difference.

A tree-wide sweep for the same signature (`-{2,}\s+\}\}`) found the defect **twice**, both in
`pos/create.blade.php`. The second, on the Zone 1 banner, happened to swallow only the comment
that followed it, so no markup was lost and nothing was visibly wrong — a latent version of
the same bug that would have deleted any markup later inserted there. Both are fixed. The
other 105 Blade files are clean, as is the check for an opener with no closer at all.


---

## 10. Stage status — honest accounting

| Stage | Status |
|---|---|
| 1. Migrations & database | ✅ **Complete** — 19 migrations, 131 tables |
| 2. Models & relationships | ✅ **Complete** — 51 core + 58 module models, all verified |
| 4. Middleware, roles & permissions | ✅ **Complete** — 5 middleware, 2 groups, 181 permissions, tenant-namespaced roles, tenant provisioning |
| 6. Services / events / listeners | ✅ **Core complete** — 8 services, 11 events, 3 listeners |
| 7. Run migrations & verify | ✅ **Complete** — all green (§9) |
| 3. Routes & controllers | ⚠️ **Partial** — items 1–6 done; 7–12 outstanding (§10.2) |
| 5. Views / frontend | ⚠️ **Foundation complete, screens partial** — items 1–6 done; 7–12 outstanding (§10.2) |

### 10.1 Build progress — items 1–12

Each line is written as the item lands.

| Item | Status | Controllers | Screens |
|---|---|---|---|
| 1. Products | ✅ Done | 8 (`Product`, `Taxonomy`, `VariationTemplate`, `Warranty`, `SellingPriceGroup`, `Discount`, `Labels`, `ImportProducts`) | 14 (products index/create/edit/show/selling-prices/price-history/stock-history, variation-template ×4, labels ×2, import) |
| 2. Contacts | ✅ Done | 2 (`Contact`, `CustomerGroup`) | 7 (index, create, edit, show, ledger, opening-balance, import) |
| 3. Purchases | ✅ Done | 4 (`Purchase`, `PurchaseOrder`, `PurchaseRequisition`, `PurchaseReturn`) | 10 (purchase index/create/edit/show + shared `_form`/`_line`/`pdf`, order index/create/edit/show, requisition index/create/edit/show, return index/create/show — all served by the shared purchase views) |
| 4. Sales / POS | ✅ Done | 5 (`Sell`, `SellPos`, `SalesOrder`, `SellReturn`, `Shipment`) | 11 (sell index/create/edit/show + shared `_form`/`_line`, sales-order index/create/edit/show served by those same views, **`pos/create` — the terminal**, sell-return index/create/show, shipments index) |
| 5. Payments & finance | ✅ Done | 5 (`TransactionPayment`, `Expense`, `ExpenseCategory`, `Account`, `CashRegister`) — 1,879 lines, 42 methods | 23 (`payment` index/create/edit/show + `_form`, `expense` index/create/edit/show + `_form`, `expense_category` index/create/edit + `_form`, `account` index/create/edit/show + `_form`, `cash_register` index/create/show/close) |
| 6. Stock | ✅ Done | 3 (`StockAdjustment`, `StockTransfer`, `OpeningStock`) — 807 lines, 20 methods | 12 (`stock_adjustment` index/create/edit/show + `_form`/`_line`, `stock_transfer` index/create/show + `_line`, `opening_stock` index/edit) — 1,947 lines |
| 7. Reports | 🟡 First tranche done (5 of 12) | 1 (`Report`) + `ReportService` + `<x-report-filters>` | 6 (`report` index/purchase-sell/stock/profit-loss/tax/expenses) — hub plus the five |

**Item 5, verified rather than assumed:** 38 routes across the five modules — full CRUD plus
the account operations (`deposit`, `withdraw`, `transfer`, `setClosed`, transaction
update/destroy) and the drawer-close flow (`closeForm`/`close`). All 19 of its named GET
routes are walked by `ScreensRenderTest` — **none is in the `SKIP` list** — and the suite is
green. Note the view directories are singular and underscored (`payment/`, `expense/`,
`expense_category/`, `account/`, `cash_register/`) while the route names are plural and
hyphenated (`payments.*`, `expense-categories.*`, `cash-register.*`); looking for
`resources/views/payments/` finds nothing and invites the wrong conclusion.

The one functional gap that remained inside this item, deferred with approval and tracked as
**§12.1**, is now closed: cash paid *out* of the drawer (a supplier payment or an expense
settled in cash) wrote no drawer row, so `cash_in_hand` read high. **Fixed 2026-08-24** by a
separate additive migration that widened the `transaction_type` enum with `ALTER TABLE` — see
§12.1 and §10.2 item A.

**Item 6, verified rather than assumed:** 17 routes across the three modules, 9 of them named
GET screens, and **all 9 are walked by `ScreensRenderTest`** — none is in the `SKIP` list.
Seventeen is fewer than three CRUD resources would produce (21 before counting the receive
action), because two of the three documents deliberately offer less than full CRUD. The shapes
are worth stating, since they read as gaps and are not:

- **A transfer has no edit and no update.** Its only mutation after saving is
  `stock-transfers.updateStatus` — receiving it. Editing a document whose goods are already on
  a van would have to decide what the van is now carrying; the app declines to invent an
  answer, so a wrong transfer is deleted and re-entered while it is still in transit.
- **Opening stock has no create and no store.** `opening-stock.edit` *is* the create screen:
  "this product's opening position at this shop" is one fact that either has been stated or has
  not, so the route table is `index` / `edit` / `update` / `destroy` keyed by `{productId}`.
  This is also why its route parameter is named `{productId}` and not `{id}` — it addresses a
  product, not a document.
- **Only the adjustment is ordinary CRUD**, and even it has no `update`-by-line semantics: the
  service reverses the entire document and rewrites it (§8.4).

**The walk grew from 100 screens to 109, and that is the weakest of the three guards here.**
A render walk proves a screen returns 200 with no untranslated key — it proves nothing about
whether stock arithmetic is right, because a screen that displays a wrong number displays it
with an HTTP 200. So the walk was extended *and* backed by
`tests/Feature/Inventory/StockMovementsTest.php` — 19 behavioural tests, 128 assertions, built
around one assertion repeated in every single one of them:

```php
$reconcile = $this->stock->reconcile($variation->id, $location->id);
$this->assertSame(0.0, $reconcile['difference']);
```

Two records have to agree — the cached `variation_location_details.qty_available` and the FIFO
map in `transaction_sell_lines_purchase_lines` — and they are written by **separate calls**
(`consume()`/`release()`/`reduceLotQuantity()` touch only the map; `adjustCachedQuantity()` is
the caller's job). Every path that updates one and forgets the other is a silent divergence
that no screen would show and no error would report, which is exactly the class of bug a render
walk cannot see. Three of those tests are shaped to catch specific mistakes rather than to
demonstrate the happy path: the refusal tests assert **nothing survives** the abort (zero
document rows *and* stock still reconciling, which is what proves the rollback rather than just
proving an exception was thrown); the in-transit delete test asserts the destination lands on
`0.0` and not `−4.0`, which is the bug a symmetrical-looking delete invites; and the
opening-stock restate test asserts the **same** `PurchaseLine` id survives the edit, because a
delete-and-recreate there would orphan every sale that had consumed from that lot.

**Extending the walk needed three route registrations and one service signature change.**
`stock-transfers.show`, `stock-adjustments.show` and `stock-adjustments.edit` are bare `{id}`
routes, so they fell through `resolveParameters()`'s `default => $this->fixtureProductId`, were
handed a product id, and 404ed — outside the accepted `[200, 302]`. They now resolve against
real seeded documents. Seeding those documents is what forced the signature change:
`ScreensRenderTest::setUp()` has no authenticated user (it calls `actingAs` inside the test
method), and `OpeningStockService::save()` was reading `auth()->id()` directly, so the insert
failed on a non-nullable `created_by`. It now takes a trailing `?int $createdBy = null` that
falls back to `auth()->id()` — chosen over adding `actingAs()` to `setUp()` because the other
two stock services already accept `created_by` in their data array, so this made the odd one
out consistent instead of adding hidden auth state to every fixture.

The stock fixtures are also the first in the suite to need a **second business location** — a
transfer is the only document in the app that cannot be written with one shop. It is created
with its own `location.{id}` permission even though an admin bypasses the location gate, so a
restricted role sees it too; without that the fixture would be silently owner-only, and
`BusinessLocation::forDropdown()` would hide it from everybody else. It has a second effect
worth having: every location dropdown on every screen in the app now has two options, which is
the only way the "pick two different ones" markup gets exercised at all. The transfer is seeded
**in transit** rather than completed, because in-transit is the state that carries markup
nothing else covers — the receive button, the in-transit banner, the pending-receipt wording
and the warning-toned stat.


**UI:** every Blade file was rebuilt against the v2 design system in three passes — shared
primitives, then the existing screens, then the outstanding sales screens authored
directly to the new standard. 69 files: 53 screens and partials, 10 components, the layout
and its 4 partials, and the published paginator. Decisions in §8.1–8.4. Item 6's 12 views were
then authored straight against v2.2 (§11.7) with no retrofit pass — the first item where that
was true, which is the whole point of having put the depth and motion work into the primitives
before building them.

**Regression guard added:** `tests/Feature/ScreensRenderTest.php` walks the route table and
renders every GET screen as an admin. New screens are covered automatically — no test to
write per item. It already caught 4 real parameter-resolution gaps.

### 10.2 What remains

Still to build, in dependency order — all of it sits on services that already exist and
are pinned by passing tests, so this is wiring, not design. **Items 5 and 6 are done and have
moved to §10.1**; the numbering below is kept as-is so references elsewhere in this file stay
valid.

7. **Reports** — `ReportController`. ✅ **First tranche DONE 2026-08-24 — see §13.** Code, tests
   and documentation all landed; `ReportsTest` (17 tests) and `ApiResponseTest` (14 tests) are
   green inside the 102-test suite.
   **The count is 12 permission-gated report screens, not the "≈40" recorded here previously.**
   That figure was the source system's `ReportController` method count; most of the surplus was
   JSON/AJAX sub-endpoints of the same DataTables screens, not separate reports.
   Excludes Indian GST per decision #2.
   **Built in this tranche (5):** `purchase_n_sell_report`, `stock_report`, `profit_loss_report`,
   `tax_report`, `expense_report` — plus the shared infrastructure (`ReportService`,
   `<x-report-filters>`, the whitelisted `export()`).
   **Deferred (7), and they inherit all of the above — the item is NOT finished:**
   `contacts_report`, `register_report`, `trending_product_report`, `sales_representative`,
   `report.stock_details`, `customer_group_report`, `user_performance_report`. Each needs a
   service method, an action, a route, a view and its keys — no infrastructure.
   §12.5 is now **closed** by `ApiResponseTest`; §12.3 stays 🟡 as settled, with no behaviour
   change. The profit report's test found a real bug in `SellService` rather than in reporting —
   §13.6, Bug 3.
8. **Settings** — `BusinessController`, `BusinessLocationController`,
   `InvoiceSchemeController`, `InvoiceLayoutController`, `BarcodeController`,
   `PrinterController`, `NotificationTemplateController`, `RoleController`,
   `ManageUserController`.
9. **Printing** — `PrintPreviewController` + RTL layout templates.
10. **Offline PWA** — `Api\OfflineDataController`, `Api\OfflineSyncController`, service
    worker, IndexedDB layer.
11. **Modules' controllers/views** — Accounting, Essentials/HRM, Superadmin,
    AssetManagement, Cms, InventoryManagement, ProductCatalogue (models + schema done).
12. **Scheduled commands** — recurring invoices/expenses, reward-point expiry, payment
    reminders, low-stock alerts, backup.

**Queued fixes, in the order you set for them:**

- **A — the drawer payout fix (§12.1). ✅ DONE 2026-08-24.** Approved by you on 2026-08-23 and
  deferred deliberately so it would not interrupt item 6; taken as its own task once item 6
  landed. A fifth `payout` value was added to `cash_register_transactions.transaction_type` by
  a **separate additive migration using `ALTER TABLE`**, per your instruction — no
  `migrate:fresh`, no data loss, and the development database and its admin account untouched.
  `isDrawerMovement()` now admits the purchase and expense document types, `summary()` reports
  `payouts` net of reversals, and the close rail and session screen state the figure instead of
  netting it silently. Covered by `CashDrawerTest` (11 tests). Full write-up in §12.1,
  including the one edge (`opening_balance`) deliberately left out and why.
- **B — the frontend design overhaul. ✅ DONE 2026-08-24 — see §11.7.** Your directive of
  2026-08-24: real SaaS-grade depth (layered shadows, subtle gradients, consistent radii),
  micro-interactions on hover/focus/click and on element entry, genuine hierarchy through
  size/weight/contrast and whitespace, and the professional details — consistent icons,
  designed empty states, skeleton loading, distinctive cards. Applied to the central design
  system so every screen inherits it; CSS-only for performance, every effect RTL-safe. The
  adopted principles are now written up in **§11.7** as the reference for later screens.
  `image_url` display landed in the same pass: `Product::hasImage()`, the
  `<x-product-thumb>` component, thumbnails on the product table and detail panel, and
  pictures in the POS grid behind the grid-level `.product-grid-media` decision. What was
  deliberately left out is recorded at the end of §11.7. §12.4 was a separate concern from this
  one — the *section-structure* retrofit rather than the visual overhaul — and has since landed
  as item C below.
- **C — the §12.4 section-structure retrofit of the pre-v2.1 screens. ✅ DONE 2026-08-24 — see
  §12.4.** Not a new decision: §12.4 has always been scheduled *after item 6 and before Reports*,
  so that no screen is edited twice, and item 6 landing is what opened that window. Sequenced
  after A because A was the smaller and more consequential of the two — a drawer that does not
  reconcile is a wrong number, and a screen without eyebrow headings is a less-good screen.
  Six view files covering ten screens were retrofitted; the rest of the 51 needed nothing, because
  §12.4 is conditional on a screen *having* several distinct groups and index screens are already
  grouped by their `.filter-bar`. The audit that established this, the two deliberate non-edits,
  the `.section-head` gutter trap and the new empty-heading guard are all recorded in §12.4.

---

## 11. Design system v2.1 — "long shift, green"

Your instruction: `#00a76f` as the base of the colour identity, `#c8fad6` for calm
secondary backgrounds, a complete scale in the same green-turquoise family, applied as a
**replacement for every existing brand and accent colour** so it reaches all 51 screens
automatically — plus a professional, non-template section structure inside each page.

Both halves are implemented in `resources/css/app.css`. This section records the final hex
values and the reasoning, because two of the decisions are departures from the literal
brief and you should be able to see exactly why.

### 11.1 Why `#00a76f` is the identity colour but not the button fill

`#00a76f` against white text measures **3.11:1**. AA needs 4.5:1 for the app's button text
(14 px semibold — which does *not* qualify as "large text", that needs 14 pt **bold**
≈ 18.7 px). Green is intrinsically luminous: white-on-green does not reach 4.5:1 until
roughly `#008560`. An intermediate candidate, `#008f6b`, measured 4.09:1 and was rejected.

So the hex you gave is used **everywhere it is a surface accent** — focus rings, active
borders, the checkbox `accent-color`, icon glyphs, tints, the PWA theme colour, the
`<meta name="theme-color">` browsers paint the mobile address bar with — and the fill under
white text is one step down. This is the "درجة أغمق منها" your brief already allows for
hover/active states, applied one rank earlier. `#00a76f` is what the product *looks* like;
`#00845d` is what a button *is*.

Every ratio below was computed from the WCAG relative-luminance formula, not estimated.

### 11.2 Final palette

The scales are declared in `@theme` **under Tailwind's own colour names** (`slate`,
`emerald`, `rose`, `amber`, `sky`), which overrides the framework's ramps. That is the
mechanism that makes this automatic: a screen written as `text-slate-500` or `bg-emerald-50`
inherits the system with no edit, so a token change propagates to all 51 screens without
touching a single Blade file. Only `brand` and `accent` are new names.

**Brand** — green-turquoise, drifting from green (160°) at 500 toward teal (172°) at 700,
which is what makes the dark end read as depth rather than as a different colour.

| Token | Hex | Contrast | Used for |
|---|---|---|---|
| `brand-50` | `#eefaf3` | — | page washes, hovered table rows, `.nav-link-active`, `.stat-icon` ground |
| `brand-100` | **`#c8fad6`** | 6.55:1 under `brand-800` | **your hex** — `.badge-brand`, `avatar`, `.tab-count` on the active tab |
| `brand-200` | `#9bf0bd` | — | borders on tinted surfaces |
| `brand-300` | `#5be49b` | — | hovered tile ring |
| `brand-400` | `#1fc98a` | — | reserved (decorative only) |
| `brand-500` | **`#00a76f`** | 3.11:1 on white | **your hex — THE brand colour.** Focus rings, active borders, checkbox tint, glyphs. Never a ground under white text. |
| `brand-600` | `#00845d` | **4.71:1** with white | `.btn-primary`, `.chip-active`, `.page-link-active`, the sidebar and login marks |
| `brand-700` | `#007867` | 5.41:1 on white | hover/active on the above, and brand-coloured **text** on white (`link`, `.section-eyebrow`) |
| `brand-800` | `#005f54` | 7.60:1 on white | text on `brand-50`/`brand-100` tints, `.tab-active`, POS price figures |
| `brand-900` | `#004b50` | 10.2:1 on white | headings on tinted grounds |
| `brand-950` | `#00302f` | — | reserved |

**Accent** — the money-commit rank. Same family, one register deeper and more teal (174°).

| Token | Hex | Contrast | Used for |
|---|---|---|---|
| `accent-50` … `accent-300` | `#e9f7f5` `#c6ebe6` `#94d8d1` `#5cbdb6` | 7.74:1 (100 under 800) | `.badge-accent` |
| `accent-400` / `accent-500` | `#2f9f9a` / `#16847f` | — | reserved |
| `accent-600` | `#006d63` | 6.24:1 | reserved for a lighter commit state |
| `accent-700` | `#005a54` | **8.11:1** with white | **`.btn-accent`** — the pay button |
| `accent-800` / `accent-900` | `#004b50` / `#003b40` | — | its hover and active |

**Neutrals** — the `slate` ramp re-tinted from warm blue-grey to **green**-grey, so the
canvas sits inside the brand's family instead of arguing with it. Contrast re-verified at
every text shade, because a hue shift changes luminance even when the "step" looks the same.

| Token | Hex | On white | Role |
|---|---|---|---|
| `slate-50` | `#f6f9f8` | — | `.surface-quiet`, `.filter-bar`, footers |
| `slate-100` | `#eef3f1` | — | page canvas, table header tint |
| `slate-200` | `#dee6e3` | — | control borders, the few real dividers |
| `slate-300` | `#c2cfcb` | — | input borders |
| `slate-400` | `#7b8d88` | 3.50:1 | **placeholders and decoration only — never body text** |
| `slate-500` | `#5c6f6a` | 5.34:1 | secondary text, hints |
| `slate-600` | `#465752` | 7.65:1 | table headers, labels |
| `slate-700` | `#374742` | 9.80:1 | body text |
| `slate-800` | `#26332f` | 13.1:1 | emphasis |
| `slate-900` | `#18211f` | 16.5:1 | headings — and the shadow tint |
| `slate-950` | `#0f1615` | — | reserved |

**Success** — moved off `emerald` proper to leaf green (`#3c8c4d`, ≈133°), about 28° from
the brand. It *had* to move: v2's desaturated emerald sat at ≈153°, and once the brand is
green a "paid" badge beside a brand badge in a 7°-adjacent green reads as a rendering
accident rather than as two meanings. `emerald-700` `#285e34` on `emerald-50` `#f0f8f1`
measures 7.08:1.

`#f0f8f1` `#daeedd` `#b5dcbc` `#87c392` `#59a668` `#3c8c4d` `#31743f` `#285e34` `#224d2c` `#1d4026`

**`rose`, `amber` and `sky` are deliberately unchanged.** They are already unrelated hues
carrying unambiguous meanings (destructive, warning, informational), and the brief asked to
replace the brand and accent colours, not the semantic ones. Re-tinting a danger colour
toward the brand family would be the one change in this pass that made the UI *less* clear.

**Elevation** is tinted with `#18211f` — the neutral ramp's own darkest value — rather than
black, so a shadow reads as the surface lifting off the canvas instead of as a grey smear
under it:

```
--shadow-panel:   three stacked layers at 6 % (contact + short + wide diffuse)
--shadow-card:    0 1px 2px / 0 1px 3px at 4–5 %
--shadow-raised:  0 6px 16px -6px at 12 %      ← .btn-accent, brand marks
--shadow-overlay: 0 24px 48px -16px at 20 %    ← modals, popovers
```

### 11.3 Rank by depth, not by hue

You asked for one family to replace **both** brand and accent. The constraint that makes
that non-trivial: `.btn-accent` ("commits money, at most one per screen") must stay
unmistakable next to `.btn-primary` — on the POS it is the single button a cashier presses
two hundred times a day. v2 solved that with a second hue (warm copper), which meant the
loudest thing on the sales screen belonged to no other part of the system.

v2.1 ranks by **depth and elevation** instead:

| Rank | Fill | With white | Extra signal |
|---|---|---|---|
| `.btn-accent` — commits money | `accent-700` `#005a54` | 8.11:1 | `shadow-raised`; on the POS also `btn-lg btn-block` |
| `.btn-primary` — the page's main action | `brand-600` `#00845d` | 4.71:1 | flat |
| `.btn-secondary` — everything else | white | — | real 1 px border |
| `.btn-danger` — destroys | `rose` | — | the only unrelated hue on a button |

Darker reads as heavier, so the pay button still dominates peripheral vision without a
competing colour. The four ranks of §8.1 are unchanged; only what distinguishes them is.

### 11.4 Sections: how a screen is divided

The second half of your brief — professional structure, not a template. Three rules:

**Surfaces are defined by a ring and a shadow, not by a 1 px grey border.** `.card`,
`.table-wrap`, `.modal-panel` and `.popover` carry `ring-1 ring-slate-900/5` +
`--shadow-panel`; `tile` uses 8 % because a thumb has to find its edge. At 5 % alpha a ring
is an *edge*, not a line — it keeps a white card legible where it meets a white table or a
bright monitor without drawing the outline that makes a page look like a form. The
three-layer shadow is what makes this work: a single-layer shadow either has a hard lip or
disappears.

The exception, stated as a rule: **controls keep their real borders** — `.btn-secondary`,
`.input`, `.select`, `.stepper`, `.keypad-key`. *A border on a thing you click is an
affordance; a border on a thing you read is a fence.*

**Sections separate by space, tone and a title — not by rules.** `.section` is `mb-8`, half
again what v2 used, and that space plus a real heading is the whole separator. `.divider`
still exists but is now explicitly rare: a rule is for separating two things of the same
kind that must not be confused — two rows in a list, a totals line from the figures above
it — never for fencing off a section. Card headers and footers separate from the body by
padding and a `slate-50/60` wash instead of a border, because a card whose header, body and
footer are each fenced with a line is three boxes in a box.

**A four-step type ladder, and no fifth.** `.page-title` (xl bold) → `.section-title`
(base bold) → `.card-title` (base semibold) → `.section-label` (xs bold caps). Anything
needing a fifth level is really two screens.

New vocabulary, all in `@layer components`:

| Class | Purpose |
|---|---|
| `.section` / `.section-tight` | the rhythm — `mb-8` / `mb-5` |
| `.section-head` + `.section-head-text` / `.section-actions` | a section's title row, with its own actions |
| `.section-eyebrow` | tiny brand-coloured caps above a title, for context |
| `.section-title` / `.section-desc` | the heading and one line of explanation |
| `.section-label` | a quiet grouping label inside a surface |
| `.surface-quiet` | **groups content by tone alone** — `rounded-2xl bg-slate-50 p-5`, no ring, no shadow, no title bar. This is the "visual grouping without divider lines" tool; reach for it before a second nested card, which is what makes a screen look like a template. |
| `.card-subtitle` | secondary line in a card header |

**`.section-head` has no top margin — the block above it must supply the gutter.** It is
`mb-3.5 flex flex-wrap items-end justify-between gap-3`, nothing more, so a head placed after a
plain `<div class="grid …">` sits flush against it. The preceding block carries `.section`
(`mb-8`) or `.section-tight` (`mb-5`); across an `@include` boundary the *partial's* last block
has to carry it, since only the partial knows it is last. Two related notes: `.filter-bar` and
`.tab-bar` each already carry `mb-4` and read as a grouped strip in their own right, so an index
screen with a filter bar needs no `.section-tight` on top of it; and `.form-actions` is sticky
with its own `mt-6`, so the last section before a commit strip should carry no bottom gutter.

**Two headings for one block is the thing the eyebrow replaces.** When a block gets a
`.section-head`, the panel inside it drops its own `title`/`icon` — a `.section-title` repeating
what the head above already says is the forbidden fifth type level. Controls that act on the whole
section (a search box, an Add button, a record count) belong in `.section-actions`, not in the
card header. Beware the corollary: `<x-panel>` still renders a header if it has an actions slot,
so a titleless panel with actions emits an empty `<h3>` — guarded against in `ScreensRenderTest`,
see §12.4.

Two consequences worth noting. `.table thead th` is now marked off by its `slate-100/70`
tint with **no** rule under it — one line doing a job already done. And `.stat-icon` is
tinted `brand-50`/`brand-700` rather than grey: four identical grey chips in a row is the
single most template-looking thing a dashboard does.

On paper all of it is stripped — the `@media print` block resets `ring-0` as well as
`shadow-none`, because now that a ring defines a surface, leaving it in would print the
outline the screen design was built to avoid.

### 11.5 The colours that live outside the token system

Five call sites cannot read a CSS custom property, so they are kept in step **by hand**.
They are listed here because they are the ones that will silently go stale:

| File | Value | Why it is literal |
|---|---|---|
| `config/pwa.php` | `theme_color #00a76f`, `background_color #eef3f1` | A web-app manifest is JSON; an installed POS showing last year's teal in the task switcher looks like a different application. |
| `resources/views/layouts/app.blade.php:9` | `<meta name="theme-color" content="#00a76f">` | Painted by the browser chrome before any stylesheet loads. |
| `resources/views/auth/login.blade.php:8` | same | Same, and it is the first screen anyone sees. |
| `resources/views/purchase/pdf.blade.php` | `#007867` rule, `#eef3f1` / `#c2cfcb` / `#dee6e3` / `#7b8d88` | DomPDF never sees the compiled stylesheet. `brand-700`, not `brand-500`: a 2 px `#00a76f` rule is thin and washed out on paper. |
| `resources/views/labels/preview.blade.php:51` | `#eef3f1` toolbar | The sticker sheet deliberately loads only the font sheet, not `app.css`. |

Everything else — including `layouts/partials/user-menu.blade.php`, whose dropdown was a
hand-spelled `rounded-xl border border-slate-200 bg-white shadow-overlay` box and is now
just `.popover` — goes through the primitives.

### 11.6 Verified

```bash
npm run build            # 101.9 KB CSS, builds clean
php artisan view:clear
php artisan test         # 39/40 — see the note in §9
```

The compiled bundle was then read back and every hex in it enumerated: the new brand,
accent, leaf-green and green-grey values are all present, and **no v2 value survives** —
neither the old teal `#2f7073` nor the copper `#a65e15` appears anywhere in the build. That
check is the actual proof that the `@theme` override reached all 51 screens, since a screen
that still carried a hard-coded colour would show up in that list.

---

## 11.7 Depth, motion and detail — the design principles (v2.2)

**This section is the reference for every screen written from here on.** Anything below that
reads as a rule is a rule: a new screen that needs an effect not listed here needs a token
added to `resources/css/app.css` first, not an inline style.

The whole of this pass lives in **one file** — `resources/css/app.css`, which went from 1,291
lines to 1,790 as the pass first landed and stands at **1,984** once the two Tailwind 4 traps
and the `.product-tile-out` regression documented below had been fixed; 105.4 KB →
**120.9 KB** compiled (gzip 14.9 KB). Fourteen views changed, and **eight
of those changed by exactly one class** (`.rise-group`, on the nine stat rows). Only three
needed real work: `layouts/app.blade.php` for the app chrome, `pos/create.blade.php` for the
picture grid and skeleton, and the new `components/product-thumb.blade.php`. That ratio is the
point: the effects reached ~53 screens because they were written into the primitives those
screens already use, not applied screen by screen — and item 6's 12 screens then inherited all
of it without a line of CSS being added for them, which is the actual test of whether the pass
worked.

### The four movements, and nothing else

Every animation in the application is one of these. There is no fifth.

| Keyframe | What it is for | Where |
|---|---|---|
| `rise` | Content arriving — 0.5 rem up, opacity 0→1 | `.rise` on every page, `.rise-group` for a staggered row |
| `fade-in` | A layer appearing behind something | `.modal-backdrop`, `.empty-state` |
| `pop-in` | A layer appearing *in front* — 0.97 scale + 0.375 rem | `.modal-panel`, `.popover` |
| `shimmer` | Work in progress | `.skeleton::after` (and `shimmer-rtl`, the one mirrored keyframe) |

**Both `rise` and `pop-in` end on `transform: none`, never on `translateY(0)` or `scale(1)`.**
This is not style. `animation-fill-mode: both` makes the final keyframe permanent, and *any*
transform value other than `none` creates a containing block — so a `scale(1)` end state on
`.modal-panel` silently reparents every `position: fixed` descendant inside it. The bug does
not appear until someone puts a fixed-position element in a modal, and then it is invisible in
the CSS and obvious only in the DOM.

### Three durations, and where the ceiling is

`--duration-fast: 110ms` · `--duration-base: 180ms` · `--duration-slow: 340ms`, with
`--ease-out-soft` for state changes and `--ease-out-quart` for arrivals.

**No state change the user is waiting on may exceed `--duration-base`.** The slow step is only
for motion that either happens once per page load, or trails an action already acknowledged
some other way — the ripple decays over 340 ms, but the button had already changed colour on
press. If a hover or a focus ring takes 340 ms the interface feels *soft*, which on a POS
being used eight hours a day reads as slow.

`.rise-group` composes with the page-level `.rise` rather than replacing it, and the stagger is
capped at 90 ms with the delays written out longhand. Six is the honest limit: a seventh tile
waiting 300 ms to appear stops reading as a flourish and starts reading as a slow page.

### Depth is a rank, not a decoration

Five elevations, and an element's shadow states its rank in the hierarchy — it is not chosen
for looks. `--shadow-card` (resting surface) → `--shadow-raised` (hover) → `--shadow-panel`
(pressed / floating panel) → `--shadow-lift` (a card lifted off the page) → `--shadow-sunken`
(inset — a well, not a surface). Every one is layered: a tight contact shadow plus a wide soft
one, because a single blur reads as a drop shadow from 2011.

`--inset-shadow-highlight` is the top-edge light line that makes a filled control look like a
physical object. It lives in `@theme`, not in `:root`, **specifically so it also registers an
`inset-shadow-highlight` utility** — see the ring trap below.

### Gradients: vertical, 8 %, and alpha where they can be

All five gradients run `to bottom`. Not one runs `to right`, and this is an RTL decision rather
than a taste one: a horizontal gradient lights the same button from the left in English and
from the right in Arabic, so the two languages get two different-looking buttons from one rule.

The lightness range is about 8 %. Above roughly 12 % a "subtle" gradient becomes a visible
band, and on a screen of 25 POS tiles it becomes a pattern.

`--sheen` and `--sheen-soft` are **alpha** washes, not colour gradients, for a mechanical
reason: **CSS cannot interpolate gradients.** A `background-image` swap on hover jumps
discretely at the transition midpoint. One fixed alpha sheen over an animatable
`background-color` gives a real transition; two gradients give a flicker.

### Honest affordances

`.card` gets the surface gradient and **deliberately no hover state**. A container that lifts
under the cursor claims to be a control. Cards that really are clickable opt in with
`.card-interactive`; `.tile` and `.btn` are controls by definition and carry their own.

Hover states are safe on touchscreens for free: Tailwind 4 compiles `hover:` inside
`@media (hover: hover)`. (The `@media (hover: none)` block in this stylesheet only kills the
tap highlight — it is not what suppresses the lift, and an earlier comment here claiming
otherwise was wrong.)

### Click feedback with no JavaScript and no per-button markup

`.btn::after` is a `currentColor` radial gradient, spread and transparent at rest, and on
`:active` it snaps to `scale(0)` at 0.26 opacity with `transition: 0s` before easing back out
over 340 ms. Because it is `currentColor` it inherits each button rank's own colour
automatically, so `.btn-primary`, `.btn-danger` and `.btn-ghost` all get a correctly-tinted
ripple from one rule and no button anywhere needs an extra element. It fires on *release*, not
press, which is what a physical button does. `.btn` gained `overflow-hidden` to clip it —
checked against the notification badge, which hangs outside its button, but `.btn-icon` does
not `@apply btn` so the badge is unaffected.

### Two Tailwind 4 traps that cost real bugs in this pass

**1. A raw `box-shadow` beside `@apply ring-1` silently erases the ring.** Tailwind emits
plain declarations as a *separate, later rule with the same specificity*, and `ring-*` is
implemented as a `box-shadow` layer (`--tw-ring-shadow`). So this looks right and is not:

```css
.empty-state-icon {
    @apply ring-1 ring-brand-600/[0.12];
    box-shadow: var(--inset-shadow-highlight), var(--shadow-card);  /* ← ring gone */
}
```

The rule: **never write a raw `box-shadow` on a ringed element.** Use the utilities
(`shadow-card`, `inset-shadow-highlight`) so Tailwind composes them into the *same*
`box-shadow` as the ring. Both `.empty-state-icon` and `.stat-icon` shipped with this bug and
were only caught by reading the compiled CSS back — source review had passed them twice.
Buttons carry no ring, which is why raw `box-shadow` is still correct there.

**2. `--tw-ring-color` cannot be transitioned.** Tailwind registers it as
`@property --tw-ring-color { syntax: "*" }` — untyped, therefore un-interpolatable, so a
transition on it jumps at the midpoint instead of easing. It is out of every
`transition-property` list in this file; a 1 px ring simply snapping is the correct trade.

Also worth knowing: **`@apply` copies declarations, never nested rules.** An `& img {}` inside
`@utility thumb` reaches `.thumb` and none of `.thumb-sm` / `.thumb-md` / `.thumb-lg` /
`.thumb-tile`, which is why their image sizing is one explicit selector list. And Tailwind 4
refuses `@apply` of a class declared in the same `@layer components` block, which is why a
couple of rules here are written out twice rather than composed.

### Skeletons, empty states, and the difference between them

A **skeleton** is the right answer to a region with nothing in it. It is the wrong answer to a
region that already holds valid, usable data — swapping working results for grey boxes takes
something away from the user in order to tell them something is coming. Hence `.is-busy`,
which dims to 55 % and **keeps pointer events**: in the POS the previous results stay tappable
while the next search is in flight, and the cashier is looking at a real product.

`.skeleton-tile` copies every value from `.product-tile` — same `min-h-24`, same
`justify-between`, same padding and ring. That is the entire purpose of it: if the two ever
drift, the grid reflows when the products land, which is the exact jump a skeleton exists to
prevent.

An **empty state** distinguishes *nothing exists yet* from *your filter matched nothing*.
"Add your first product" is useless advice to someone whose search simply missed, so
`product/index.blade.php` checks every filter, not just the search box, before choosing which
message and which action to show.

### Product pictures: `<x-product-thumb>` and the grid-level decision

Four sizes and no others — `.thumb-sm` (table cell), `.thumb-md`, `.thumb-lg` (detail panel),
`.thumb-tile` (POS / catalogue) — all `object-cover` in a fixed box, so a portrait photo, a
wide banner and a missing file occupy identical space and no row or tile can be knocked out of
alignment by whatever a supplier uploaded.

`Product::hasImage()` exists because `image_url` **never returns null** — it falls back to a
placeholder SVG so an `<img>` is never broken, which means it cannot answer "is there a
picture". The distinction matters: a screen showing the same placeholder bitmap in every cell
of a half-populated catalogue looks *defective*, where the same screen showing a muted icon
looks *incomplete*, which is what it is. `ProductController::getProducts()` gates the JSON
field on it and sends `null`, not the placeholder URL, so the POS can branch.

**The POS grid decides whether it is a picture grid; the tiles follow.**
`.product-grid-media` is added when at least one product in the *current result set* has a
photo, and without it no tile draws an image box at all. Two reasons, both concrete:

* A catalogue with no photos would otherwise show a screen of identical placeholder icons —
  which is not information, and costs the vertical room for roughly four more products the
  cashier can see at a glance.
* Decided per *tile* the grid comes out ragged: CSS grid stretches every tile in a row to the
  tallest, so a text-only tile beside a picture tile becomes a tall box with its name and
  price pushed to opposite ends.

It is re-decided per response, not once per page, because a mostly-photographed catalogue can
easily have a search that returns the three products nobody photographed. `SellPosController`
seeds the flag from `EXISTS(image)` so the *first* skeleton already has the shape of the tiles
about to replace it — a hint, not the truth, since it cannot check the file is on disk; the
first response corrects it either way.

### RTL is structural, not a patch

Logical properties throughout — `ms`/`me`, `ps`/`pe`, `start`/`end`, `inset-inline-start`. The
nav active bar is `inset-inline-start: 0`, so it moves to the correct edge with no override.
All gradients are vertical (above). **Exactly two things could not be done logically**, and
both carry an explicit `[dir='rtl']` rule:

* **Chrome shadows** (`.app-sidebar`, `.app-brand`, `.app-header`, `.app-footer`).
  `box-shadow` offsets are signed numbers with no logical equivalent, so the sidebar's shadow
  is mirrored by hand.
* **The skeleton shimmer**, which sweeps in the reading direction — `shimmer-rtl` is the same
  keyframe with the translate reversed.

If a third exception ever appears, it belongs in this list.

### Reduced motion, honestly

```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-delay: 0ms !important;          /* ← without this, staggered */
        animation-iteration-count: 1 !important;  /*    children stay invisible */
        transition-duration: 0.01ms !important;
    }
}
```

Zeroing the duration alone is the version everyone ships and it is not enough. The delay
override is load-bearing: `.rise-group` children would still wait out their `animation-delay`
with nothing painted, so a user who asked for less motion would get a *blank row* instead of
no animation. And `infinite` at 0.01 ms is a repaint loop, which is why the shimmer is pinned
to one iteration.

### Performance

Only `opacity`, `transform`/`translate`, `box-shadow` and `background-color` are animated —
compositor-friendly properties, no layout thrash. No JavaScript animation library, and the
ripple, the skeleton sweep, the page rise and the stagger are all pure CSS. Total cost for the
whole pass: **+14 KB uncompressed, +2.3 KB gzipped, zero KB of JavaScript.**

One JavaScript consequence worth recording, because it is not obvious: the page-level `.rise`
broke the POS shell measurement. `fitShell()` used `getBoundingClientRect().top`, which
*includes* the 8 px rise transform, so for the animation's first 340 ms it read the shell 8 px
low and sized it 8 px short. It now walks `offsetTop` up the `offsetParent` chain — pure
layout, transform-immune. `.rise` also sits on the inner wrapper inside `<main>`, never on
`<main>` itself, so the animation's end state cannot turn the scroll container into a
containing block.

### Deliberately not done

`sell/_line.blade.php` and `purchase/_line.blade.php` did **not** get thumbnails. They are
line-item editors, not product cards: every row already carries qty and price inputs, and the
user picked the product a second earlier. The picture would cost first-column width on a dense
editing table to identify something not in question. Recorded as a decision, not an omission —
if it turns out to be wanted on the *edit* screens (where 20 saved lines really are being
audited), the data is already there via `$line->variations->product`.

### 11.7.1 Verified

```bash
npm run build    # 121.26 KB CSS (gzip 14.92 KB), builds clean
php artisan test # 71 tests, 336 assertions — all passing
```

**The compiled CSS was read back and asserted on, not trusted from source.** Source review
passed twice on rules that were broken, so the checks that matter were run against
`public/build/assets/app-*.css`:

* `.empty-state-icon` and `.stat-icon` both still carry `--tw-ring-shadow` inside their final
  composed `box-shadow` — the ring-erasure bug is gone.
* `.app-brand-mark` keeps its raw `box-shadow`, and the dump confirms it has **no**
  `--tw-ring-shadow` to erase, so that one is correct as written.
* No `transition-property` anywhere in the bundle contains `ring`.
* `@keyframes rise` ends on `transform: none`, not `translateY(0)`.
* `.product-tile-out`'s rose fill and ring are emitted *after* `.product-tile`'s gradient and
  hover rules, so the override actually wins — verified by byte offset, since these are
  equal-specificity rules decided purely by source order.
* The reduced-motion block carries all four `!important` declarations.

`pos.create` is inside `ScreensRenderTest`'s walk and not in its `SKIP` list, so the suite
passing is also the proof that `$hasProductImages` reaches the view and the new markup renders.

**Corrected 2026-08-24 — that last sentence claimed more than the walk could deliver.** At the
time it was written the walk asserted only a status code and the absence of raw `lang_v1.*`
keys, neither of which can see whether markup is *structured* correctly. It rendered
`pos.create` green while the cart's wrapping `<div>` was missing and the column was scattered
across the terminal's grid cells. The walk now also asserts balanced `<div>` markup on every
screen, and a second test asserts the cart column's containment and order through the DOM, so
the sentence is true as of §9.2 — but it was not true when first written, and the distinction
between "the screen returned 200" and "the screen is correct" is the whole lesson.

**One regression this pass introduced and fixed.** `.product-tile-out` (the out-of-stock POS
tile) was `@apply border-rose-200 bg-rose-50/60` — written when `.tile` had a border. It now
has a *ring*, so the `border-color` had no width to land on, and worse, `.tile`'s new **opaque**
`--gradient-surface` painted straight over the rose `background-color`, making the out-of-stock
flag invisible on the busiest screen in the shop. Fixed to `bg-rose-50 ring-rose-300` with
`--sheen-soft` (alpha) as the wash. The whole stylesheet and every view were then swept for the
same shape — a `background-color` tint over an opaque `background-image` — and this was the only
instance: `.badge-*`, `.alert-*` and `.btn-icon-*` carry no gradient, and `.btn-danger`'s sheen
is alpha.

---

## 12. أمور يجب معالجتها قبل الإطلاق — Must fix before launch

Deferred deliberately, each with your approval or under decision #8, and each recorded here
so that nothing silently ships. **This section is a release gate, not a wish list.** Order is
by consequence, not by effort.

### 12.1 ✅ RESOLVED — cash paid out of the drawer is now counted in the shift

**Fixed 2026-08-24.** Deferred once with your approval so it would not interrupt item 6, then
taken as its own task rather than folded into Reports.

`CashRegisterService::recordableTypes()` returned sell-side documents only, so a payment to a
supplier or an expense settled in cash out of the till wrote **no** drawer row. The money
physically left the drawer; the register did not know it. `summary()['cash_in_hand']` therefore
read **higher** than the cash actually present, the counted denominations came in short, and
the variance was attributed to the cashier. That mis-attribution was the real damage — the
arithmetic is recoverable, a cashier wrongly recorded as short is not.

**The enum gained a fifth value, by `ALTER TABLE` and not by rebuild.**
`2026_08_24_000100_add_payout_to_cash_register_transaction_type.php` widens
`cash_register_transactions.transaction_type` from four values to five
(`initial | sell | transfer | refund | payout`) with a raw `ALTER TABLE ... MODIFY`, and
narrows it again on `down()`. This was your explicit instruction and it is the right one: the
enum is declared in the original create-table migration, so the alternative — editing that
file and running `migrate:fresh` — would have rebuilt the development database and destroyed
both your data and the admin account. A separate additive migration costs one file and loses
nothing. Widening an enum needs no data backfill, because no existing row can hold the new
value.

**Naming the movement, and keeping direction separate from it.** `transaction_type` says
*what happened*; the `type` column says *which way the money went*. Keeping those two
independent is what lets one value serve both directions:
`drawerType()` files anything on the purchase side of the business as `payout`, and
`isOutgoing()` decides the direction by asking `TransactionTypes::moneyIn()` — the same
predicate the bank mirror asks, so no payment can be a receipt in one ledger and a payment in
the other. A supplier refunding cash is consequently a `payout` row with `type = credit`: it
lands against the payouts it reverses instead of inflating the shift's takings.

The sell side still splits by direction instead (`sell` in, `refund` out). That is an
inconsistency inherited from the original four values and left alone on purpose — renaming
`refund` would rewrite rows in live registers to fix nothing a reader gets wrong.

**`summary()` states payouts; it does not net them silently.** `payouts` is accumulated net of
reversals and reported as a positive quantity, because "paid out" reads as an amount rather
than as a direction. `cash_in_hand` already has the money removed, so the close rail shows the
"paid out" line explicitly (`cash_register/close.blade.php`): a cashier who watches the
expected figure drop with no explanation has no way to tell a payout from a shortage. The
session screen (`cash_register/show.blade.php`) earns a fifth stat card only on a shift that
actually had one — a permanent zero would spend a fifth of the row saying nothing happened.

**Covered by `tests/Feature/Finance/CashDrawerTest.php` (11 tests).** Six exercise the new
term — a supplier paid in cash, an expense settled at the till, a purchase return netting
payouts down rather than counting as takings, a contact-due settlement writing exactly one
parent row and no child allocation rows, a corrected payment updated in place rather than
duplicated, and a card payout that is stated without touching `cash_in_hand`. Five guard the
four movements that already worked: the exact row sequence `['initial', 'sell', 'refund']`,
change handed back reversing direction, advance balances staying out of the drawer, a payment
taken with no register open writing nothing *and* not being retroactively acquired by the next
shift, and deletion removing its row.

**One edge deliberately left open, stated rather than hidden.**
`TransactionPaymentController::documentFromRequest()` accepts any transaction id, so an
`opening_balance` document can in principle take a direct payment. `OPENING_BALANCE` is
excluded from `recordableTypes()` because its direction is genuinely ambiguous — a customer's
opening balance and a supplier's are opposite movements under the same document type — and
guessing would put a wrong sign in a cashier's drawer. Such a payment writes no drawer row,
which is the same behaviour as any back-office payment and is safe; it is recorded here so the
silence is a known decision and not an oversight.

### 12.2 ✅ RESOLVED — credentials were in plaintext in tracked files

**Fixed 2026-08-23, before the repository's first push.** Kept in this section as a record
rather than deleted, because the reasoning is what stops it recurring.

*The problem:* the password sat in `NOTES.md:9`, in the §8 decision table, and in
`AdminUserSeeder::PASSWORD`. All three are committed content, so **the first `git push`
would have published the credential** — and a published secret is compromised even if the
commit is later removed, because it stays in the history and in any clone or cache. The
account is unrestricted (`Gate::before()` grants an admin every ability), so it is a
full-control credential, not a demo login. The repository is public, which removed any
margin.

*What was done:*

- The password was **rotated** — the published-in-a-draft value is dead and seeds nothing.
- It moved to `SEED_ADMIN_PASSWORD` in `.env`, reached through
  `config('constants.seed_admin_password')`. `.gitignore` covers `.env` (verified against
  the staged file list, not just the pattern).
- `AdminUserSeeder::PASSWORD` became `AdminUserSeeder::password()`, which **throws** when
  the variable is unset instead of falling back to a default — an unrestricted account must
  never be reachable by a guessable password.
- The seeder no longer echoes the password to the console.
- `.env.example` documents the key; `.env.testing` (which *is* tracked) carries an obvious
  test-only value that protects nothing.
- **A second credential was found in the same sweep:** tracked `.env.testing` carried an
  `APP_KEY`, and `.env` used the **identical** key — so the push would have published the
  real signing key for the development environment (Laravel uses `APP_KEY` for cookie and
  session signing, `encrypt()`, and signed URLs). Both were rotated to **distinct** values:
  `.env.testing` now holds a throwaway test key, `.env` a fresh private one. Blast radius of
  the rotation was checked first and is nil beyond invalidating existing sessions — the
  codebase has no `encrypted` casts, no `Crypt::` calls and no encrypted columns.
- The single pre-push commit was amended, so the credential never entered git history at
  all — no rewrite of published history was needed.

*Standing rule:* no credential in a tracked file. `.env.testing` is tracked — anything put
there is public by definition, **including its `APP_KEY`**, which must therefore never match
the key in `.env`.


### 12.3 🟡 The Admin role carries no explicit permissions

By design, mirroring the source system: `Gate::before()` short-circuits every check for an
admin. It is recorded here because it means **permission changes cannot restrict an admin at
all** — there is no way to grant an administrator less than everything. Fine for a single
operator; revisit before multi-admin tenants.

**Decision taken 2026-08-24, ahead of the Reports item: leave it exactly as it is.**
`Gate::before()` is untouched, and the reports enforce permissions for non-admins only — which is
already what `Controller::permit()` does. **No behaviour changed; this paragraph is the whole of
the work.**

Reports were the natural moment to ask, because they are the first screens where "this role may
see the figures, that one may not" stops being theoretical. The answer is still no, for two
reasons. The change is not report-shaped: `Gate::before()` is the gate every screen in the system
leans on, so making an admin restrictable is a system-wide change needing a system-wide review,
not a line added while building a report. And the system currently has one owner, so the change
would buy nothing today while putting every existing screen's authorisation back in question.

Deliberately left **🟡 rather than closed**, per the owner's choice: the decision is recorded and
the reports are built to respect permissions like everything else, but the question is parked for
a calm re-read once the reports are actually in use — with real multi-manager requirements in
front of us instead of a guess. The concrete trigger for revisiting: a branch manager who should
see their own branch's figures but not the whole business's profit.

### 12.4 ✅ Section-structure retrofit of the pre-v2.1 screens

**Resolved.** Six view files, covering ten screens, now carry the §11.4 grouping:
`sell/show`, `sell/_form`, `purchase/_form`, `product/_form`, `product/show`, `product/edit`.
Eleven keys were added to each of `lang/en/lang_v1.php` and `lang/ar/lang_v1.php`.

The pattern applied, in each case:

- **An eyebrow head names a second subject, not a third card.** Where a block answered a
  different question from the one above it — shipping after an invoice, where a product may be
  sold as against what it is — it got a `.section-head` and the panel *lost* its own title.
  Two headings for one block is exactly what the eyebrow exists to replace.
- **Controls and counts moved up into `.section-actions`.** A card header carrying both a title
  and the search box that fills the card was doing two jobs at one type level.
- **`mt-6` became `.section`**, so the gutter comes from the vocabulary rather than a one-off.

**The retrofit was conditional, and most of the backlog correctly needed nothing.** §12.4's own
text scopes it to *"where a screen has several distinct groups"*, and an audit found that most of
the 51 already satisfy it or have nothing to group:

- **Index screens are already named by their filter strip.** `.filter-bar` is
  `mb-4 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-900/5` — a tone-grouped region in its own
  right — and `.tab-bar` also carries `mb-4`. Adding `.section-tight` would double the gutter.
- **One content group needs no head.** `purchase/show` (items plus its summary column) and
  `contact/_form` (one group in a grid) were left alone deliberately; a head there is chrome.
- **`contact/show` and `stock_transfer/show` match the canonical shape.** `cash_register/show`
  is the §11.4 reference and it deliberately leaves its stats row and its panel grid unheaded,
  heading only the ledger table. The two screens shaped like it minus that table need nothing.

#### Correcting this section's own wording

The claim that item 6's screens "already use the grouping" was true but misleadingly phrased,
and it cost a survey. **They group through `<x-panel quiet>`, not through hand-written eyebrows:**
`components/panel.blade.php:58-60` renders `.surface-quiet` in place of `.card` and swaps
`card-header` for `section-head` when `quiet` is passed. A screen therefore satisfies §11.4 with
neither class ever appearing in its source. Grepping the twelve item-6 screens for
`section-eyebrow|section-head|surface-quiet` returns zero hits, which reads as a total absence of
the vocabulary and is a **false negative** — the classes are emitted a layer down.

The general lesson, alongside §9.2's *"when a check disagrees with a file you have read, suspect
the check"*: **in a system with a component layer, a class-name grep measures authoring style,
not compliance.** Read the component before believing the survey.

#### The trap the next retrofit will hit

**`.section-head` is `mb-3.5 flex flex-wrap items-end justify-between gap-3` — it has no top
margin at all.** The gutter above a section head comes entirely from the preceding block
carrying `.section` (`mb-8`) or `.section-tight` (`mb-5`). Adding a head without checking what
sits above it collides the two. This bit twice during the retrofit:

- `product/show`'s first grid needed `.section` added before its new variations head.
- `product/_form`'s availability panel carries `.section` **even though it is the partial's last
  block**, because `product/edit` follows the `@include` with its variation-price head. The
  gutter has to cross the include boundary, and only the partial can supply it.

Note also that `.form-actions` is `sticky bottom-0 … -mx-4 mt-6 … border-t bg-white/95`, so the
last section before a commit strip should carry *no* bottom gutter — the strip sits close to the
thing it commits.

#### New guard: headings with no text

`<x-panel>` renders its header when it has a title, an icon **or** an actions slot
(`panel.blade.php:59`), so a panel given only actions emits
`<h3 class="card-title"><span class="truncate"></span></h3>` — a blank line on screen and a
heading a screen reader announces with nothing to say. This retrofit made that easy to hit:
moving a record count out of a card header and into a `.section-head` leaves the panel titleless
but still slotted.

`ScreensRenderTest` now fails on any `<h1>`/`<h2>`/`<h3>` whose text content is empty, checked
across every screen in the route walk. It reads text content rather than the tag, because the
defect is an empty accessible *name*: an icon-only header is just as silent as an empty one.
Verified by injecting an empty `<h2>` into `product/show` and confirming the walk reported
`products.show → empty <h2>: a heading with no text`, then reverting.

#### Coverage gap, stated honestly

`product/edit`'s new head sits inside `@if ($product->type === 'variable')`, so the route walk
only reaches it when the fixture product happens to be variable. The head itself is verified by
structural check rather than by render.

#### Verified

```bash
php artisan view:clear && php artisan test   # 71 tests, 336 assertions — all passing
# lang parity: ar=906 en=906, missing_in_ar=0 missing_in_en=0, all 11 new keys present in both
```

**The assertion count is unchanged at 336 on purpose, and that is not evidence the guard is
inert.** The empty-heading check appends to the walk's existing `$failures` array and is reported
by the one `assertSame([], $failures)` already there, so it adds coverage without adding an
assertion. Because a guard that never matches anything would also be green, it was verified by
mutation rather than by the suite passing — see above.

All six touched files were additionally checked structurally: each compiles, each balances
`<div>` at +0, each has the expected head/eyebrow/section counts, and none contains the §9.2
run-on-comment signature `/-{2,}[ \t]+\}\}/`.


### 12.5 ✅ RESOLVED — the JSON endpoints now have their own test

The scope qualified its column with a table that does not exist (`locations.id` instead of
`business_locations.id`), which is a hard SQL error, and it survived undetected because the
only route that exercises it — `products.list` — is excluded from `ScreensRenderTest` for
being JSON with no view. Fixed at `app/Models/Product.php:68`. The **gap** was that the walk
cannot see JSON endpoints at all; a small API-response test was owed alongside the Reports
item, which adds many more of them.

**Closed 2026-08-24 by `tests/Feature/ApiResponseTest.php`** — 14 tests, 58 assertions. The
thirteen endpoints sitting in `ScreensRenderTest::SKIP` had no test of their own, and they are
not incidental: they are the POS product search, every contact picker, and the purchase-form
prefill. A render walk cannot see them, so nothing did.

It asserts the three things a render walk structurally cannot:

- **A JSON content type, not a redirect.** A guest hitting these gets a login page with a 200
  in some configurations, which `assertOk()` alone would pass. The walk over the twelve named
  endpoints checks status, `application/json`, **and** that the body decodes — an undecodable
  200 breaks every caller while looking healthy.
- **The field names the front end binds to.** `products.list` must answer with
  `variation_id, product_id, text, sku, unit, enable_stock, qty_available`, and
  `contacts.search` with `id, text, mobile, balance, credit_limit, pay_term_number`. Renaming
  one of these silently empties a Select2 rather than raising anything.
- **The failure modes.** A missing record is a 404 and not an empty list; a missing required
  parameter is a 422 naming the field; a guest is a 401 and not a login page; a signed-in user
  without the permission is a 403 in JSON.

`Product::scopeForLocation()` itself is now pinned four ways: an unrestricted product is
available everywhere, an explicitly restricted one is visible at its location and not at
another, `forLocation(null)` filters nothing, and the same scope is exercised through HTTP via
`products.list`. The regression that started this section would now fail three of the four.

Two traps worth carrying forward, both found by writing this file:

- **`assertSame(10.0, $decoded['ordered'])` fails against a decoded `10`.** JSON does not
  preserve PHP float types, so asserting the decoded *type* tests the runtime's precision
  setting rather than the endpoint. Cast before comparing.
- **`actingAs()` outlives a single request inside one test.** Clearing it for the guest case
  needs `auth()->logout()`, `flushSession()` **and** `$this->app['auth']->forgetGuards()`; the
  first two alone leave the resolved guard instance still holding the user.

---

## 13. Reports — the figures layer (item 7, first tranche)

Reports are the first screens in Souqly that exist only to answer a question, not to record
anything. Everything before them stores what happened; these interpret it. That changes what
"correct" means: a purchase screen that is wrong is visibly wrong, while a profit figure that is
wrong looks exactly like a profit figure that is right. This section records the decisions that
follow from that, and the three bugs the tranche surfaced.

### 13.1 The service boundary, and why it is drawn here

**`app/Services/ReportService.php` owns every figure. The controller owns none.** Not a style
preference — `scoped()` is the reason:

```php
Transaction::ofType($types)
    ->permittedLocations()
    ->forLocation($locationId)
    ->whereBetween('transactions.transaction_date', [$start.' 00:00:00', $end.' 23:59:59']);
```

That chain appeared **four times inside `HomeController::totals()` alone** before this item. Four
copies of "this month, at the locations you may see" is four chances for the dashboard and the
report to disagree about what the same words mean — and when they disagree, both look right.
There is now one definition, and `HomeController::dateRange()` delegates to it rather than
holding a second current-month default.

What deliberately did **not** move: `totals()`, `salesTrend()` and `stockAlerts()` stay in
`HomeController`. They return dashboard-shaped display keys rather than figures, and folding them
in would have been a larger change than the duplication justified.

The payoff is testability. `ReportsTest` targets the service directly, so the arithmetic is
asserted without rendering anything — which is the only way to see it at all, since a report that
computes garbage renders it beautifully.

### 13.2 Two figure decisions that are decisions, not defaults

**COGS uses `purchase_price_inc_tax`.** This slightly overstates cost of goods sold wherever
purchase tax is recoverable, and the alternative was considered. It loses to a worse problem:
`StockService::consume()` (`StockService.php:135`) already writes the tax-inclusive cost into the
FIFO map, so computing COGS from the exclusive column would make the *same sale* show two
different profits depending on which screen you opened. Agreeing with the cost the system has
already recorded beats being theoretically tidier and self-contradictory. Recorded here because
the day someone reconciles against an accountant's figure, this is the line they will need.

**Net quantity, never gross.** `(map.quantity - map.qty_returned)` and
`(sell_line.quantity - quantity_returned)` throughout, so a returned item stops counting as both
revenue and cost rather than as neither or one.

**The combo asymmetry, which is the subtlest thing in the file.** Revenue **excludes**
`children_type = 'combo'`; COGS **includes** them. That looks like an inconsistency and is not:
the child lines are what hold the FIFO map rows, so cost lives there, while price lives on the
parent. Invert either half and nothing breaks visibly — you simply get a wrong profit. Note
honestly that `salesValue()`'s own docblock records that children are priced at zero today, which
makes that particular filter arithmetically inert and future-proofing rather than load-bearing;
there is no mutation that proves it, because there is nothing yet for it to change.

### 13.3 One `export()`, whitelisted

A single action parameterised by report name rather than five near-identical methods, so there is
one `view_export_buttons` gate and one `Excel::download` call. The report name is
**whitelist-validated** against the five known slugs — the parameter reaches a method name, and a
parameter that reaches a method name is exactly the shape that must never be trusted. It re-runs
the report's own query with the request's filters, so the file matches what is on screen rather
than silently exporting an unfiltered table.

### 13.4 Deferred, and inheriting all of the above

Seven reports remain: `contacts_report`, `register_report`, `trending_product_report`,
`sales_representative`, `report.stock_details`, `customer_group_report`,
`user_performance_report`. They need a service method, a controller action, a route, a view and
their keys — not infrastructure. `ReportService`, `<x-report-filters>` and the whitelisted
`export()` are built to carry them.

**Indian GST reports are excluded permanently** per decision #2 (the market is Egypt), enforced
at `app/Support/Permissions.php:14`. **The real screen count is 12, not the "≈40" this file
recorded previously** — that figure was the source system's `ReportController` method count, and
most of the surplus was JSON/AJAX sub-endpoints of the same DataTables screens.

### 13.5 `expenseValue()` gained a `$categoryId`

The expense report's stat tiles were computed without the category filter that the table beneath
them honoured, so filtering to one category left the totals describing all of them. The tiles and
the table now take the same path. A small fix, recorded because the class of bug is worth naming:
**a summary and its detail computed by two different code paths will eventually disagree**, and
the disagreement is invisible until someone adds a filter to one of them.

### 13.6 Three bugs, and what each one teaches

**Bug 1 — `selectRaw()` is `addSelect()` under another name.** It *appends*; `select(DB::raw())`
replaces. Appending an aggregate beside the non-aggregated columns already selected, with no
`GROUP BY`, is MySQL error 1140 under the default `only_full_group_by` — a hard 500 on
`reports.stock`, not a wrong number. Caught by `ScreensRenderTest` walking the route bare.

**Bug 2 — the export route's 404 was a test artefact pointing at a real gap.**
`ScreensRenderTest::resolveParameters()` ends in `default => $this->fixtureProductId`, so
`{report}` — an unrecognised parameter name — was filled with a **product id**, which the
whitelist correctly rejected. The route was right; the walk could not express it. It is now a
`SKIP` entry with real coverage in `ApiResponseTest`, which is what §12.5 asked for.

**Bug 3 — the profit report's test found a production bug in selling, not in reporting.**
This is the one worth reading twice.

`SellService::syncLines()` ends with a cleanup sweep whose rule is *"delete every line on this
transaction that is not in `$keptIds`"*. `$keptIds` collected **parent** line ids only. So
`consumeComboComponents()` would write the component lines, consume their stock — and then the
sweep, a few statements later, would delete the lines it had just written and release the stock it
had just consumed. **On every combo sale, creates included, not only edits.**

The visible symptom: nothing. The screen showed the sale correctly. The FIFO map was empty, so
`costOfGoodsSold()` found no cost, so **every combo reported as pure margin at zero cost** — on
the one report an owner makes decisions with.

The fix threads the child ids back into `$keptIds`, and it is safe on the edit path for the same
reason the sweep exists: the *previous* save's children are absent from `$keptIds`, so they are
still released and deleted, while the ones just created survive. Both the call site and the sweep
now carry comments saying so, because the sweep is the one place in the service where a row can be
deleted without anybody asking for it.

The lesson is about method, not about combos. Having checked the cast, the column, the migration,
`$guarded`, `affectsStock()` and `enable_stock` on paper and found every one of them correct, the
thing that actually located the bug was **instrumenting the assertion message to print the real
state** instead of forming a sixth hypothesis. It printed
`lines=1 type=combo stock=true combo=[{...}] status=final` — proving the fixture was right and the
child had simply never survived. A test earned its keep before it ever guarded anything.

### 13.7 The test that measured nothing until it signed in

**`ReportsTest` initially reported a confident `0.0` for thirteen of seventeen figures.** Not
wrong arithmetic — an empty result set. `permittedLocations()` resolves against
`auth()->user()`, and `TestCase::createTenant()` binds a tenant but **authenticates nobody**, so
it returned an empty id list and the scope filtered out every row in the database.

Every assertion was passing against an empty report. That is the precise failure this file exists
to catch, reproduced inside the file itself.

`setUp()` now grants `access_all_locations` and calls `actingAs($this->user)`, with a comment
saying why. **One fix resolved eleven failures** — reading thirteen identical `0.0`s as one cause
rather than debugging thirteen calculations is what made that a short session instead of a long
one. Uniform zeros are the signature of an empty query, never of wrong maths.

Generalised: **a service-level test of anything location-scoped must authenticate, or it silently
measures nothing.** This will apply to every one of the seven deferred reports.

### 13.8 Mutation-checked, per §12.4

A green suite is not evidence a test bites. Both checks were run and reverted:

| Mutation | Expected if the test bites | Observed |
|---|---|---|
| `map.quantity` → `sl.quantity` in `costOfGoodsSold()` | COGS changes | 195 instead of 90 — caught |
| add the `children_type != 'combo'` filter to COGS | combos become costless | 0 instead of 36 — caught |

One wrong mutation is worth recording too: the first attempt mutated `salesValue()`'s filter to
`->where('map.quantity', '>', -1)`, but `salesValue()` does not join `map` — that is a SQL error,
not a meaningful mutation. Reverted, and reading the method then showed its docblock already
explains why no mutation exists there (§13.2).

**One coverage gap, stated plainly:** the `buy()` fixture sets `purchase_price` and
`purchase_price_inc_tax` to the same value, so a mutation swapping those two columns would go
uncaught — meaning §13.2's tax-inclusive-COGS decision is documented but **not pinned by a test**.
Fixing it needs a fixture with a real purchase tax, and it is the first thing to do when the next
report touches cost.

### 13.9 Verified

```bash
php artisan test    # 102 tests, 494 assertions — all passing
```

- `ReportsTest` — 17 tests, 100 assertions. Asserts the arithmetic: gross profit equals revenue
  less FIFO cost, a return reduces both sides, output tax nets against input tax, more input than
  output is reclaimable rather than owed, and a combo consumes its components.
- `ApiResponseTest` — 14 tests, 58 assertions (§12.5).
- Every report opens **bare, with no query string** — a hard constraint, since the route walk
  opens it that way. `dateRange()`'s current-month default is what makes that hold; no report may
  require a filter to render.
- A non-admin holding only `stock_report.view` sees one tile on the hub and is refused the other
  four — asserted, not eyeballed. Note that such a user needs `'allow_login' => 1` or
  `CheckUserLogin` bounces them with a 302 and the test measures the login gate instead of the
  report gates it is about.
- Lang parity `ar = 974`, `en = 974` — exact, +68 each, every new key present once in both.

### 13.10 What this tranche deliberately did not add

**No new CSS, and no new design-system work.** `@utility tile` (`app.css:562`) is already
gradient-backed, shadowed, hover-lifting and `text-start`, so the hub needed none; `.rise-group`,
`<x-stat>` and `.filter-bar` already carry the depth, micro-interaction and hierarchy the design
directive asks for, and `.filter-bar` is already inside the print block's hide list, so a printed
report drops its filter strip for free. The directive is satisfied by the central system
propagating, which is exactly what it asked for — a report needing a bespoke style would have been
a sign the system was missing a component, not a sign the report was special.

