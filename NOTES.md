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

All of this is covered by tests (§9).

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
| **Two zones and nothing else** — `.pos-shell` = product grid + `24rem` cart | Requirement 2 of the brief. Anything that is not choosing a product or reading the basket is behind a toggle. |
| **Both zones sticky, each scrolling inside itself** — matched `lg:top-20` and `max-height: calc(100vh - 6.5rem)` | The cart and the pay button never move, no matter how long the basket gets. The two numbers must stay identical or the columns visibly misalign; both call sites say so. |
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

---

## 9. Verification — what is actually proven

```bash
php artisan migrate:fresh --seed   # 18 migrations, 131 tables, 55 currencies, 181 permissions
php artisan test                   # 40 tests, 163 assertions — 39 passing, 1 known (below)
php scripts/verify-models.php      # 109 models, 321 relations, 460 casts — clean
php scripts/add-lang-keys.php      # 637 ar / 637 en — parity OK
npm run build                      # 101.9 KB CSS, builds clean
php artisan route:list             # all routes resolve
```

**The one failing test, stated plainly:** `ScreensRenderTest` walks every named GET route,
and item 5's routes are registered while their views are not yet written — so it reports 19
screens as HTTP 500/404 (`payments.*`, `expenses.*`, `expense-categories.*`,
`cash-register.*`, `accounts.*`). That is the guard doing its job, not a regression: it is
the reason the walk exists. It goes green when those views land.

| Suite | Covers |
|---|---|
| `FifoStockTest` (8) | Oldest-lot-first consumption, weighted cost, cache-vs-FIFO agreement, overselling shortfall, release on edit/delete, partial return crediting newest lot first, explicit lot override, adjustments tracked separately, purchase-shrink guard |
| `ProcureToPayCycleTest` (10) | Received vs pending purchase, full purchase→sale→payment→return cycle, purchase return capped at remaining lot, PO fulfilment `ordered→partial→completed`, payment terms + >100 % rejection, contact-due allocation with advance banking, credit-limit breach, pre-sale shortfall detection, sale deletion restoring stock |
| `StockTransactionGuardTest` (6) | Every stock/payment mutation refuses to run outside a DB transaction |
| `ApplicationSmokeTest` (10) | Login page, guest redirect, sign-in → dashboard **rendering RTL Arabic**, default tenant resources, brand creation stamped with tenant, **cross-tenant isolation**, permission refusal, `allow_login=0` block, `/api/ping`, print-agent token rejection |
| `ScreensRenderTest` (1) | Every named GET route rendered as an admin — **81 of them** — asserting no 4xx/5xx, and no raw `lang_v1.*` key in any response body |

---

## 10. Stage status — honest accounting

| Stage | Status |
|---|---|
| 1. Migrations & database | ✅ **Complete** — 18 migrations, 131 tables |
| 2. Models & relationships | ✅ **Complete** — 51 core + 58 module models, all verified |
| 4. Middleware, roles & permissions | ✅ **Complete** — 5 middleware, 2 groups, 181 permissions, tenant-namespaced roles, tenant provisioning |
| 6. Services / events / listeners | ✅ **Core complete** — 8 services, 11 events, 3 listeners |
| 7. Run migrations & verify | ✅ **Complete** — all green (§9) |
| 3. Routes & controllers | ⚠️ **Partial** — see below |
| 5. Views / frontend | ⚠️ **Foundation complete, screens partial** — see below |

### 10.1 Build progress — items 1–12

Each line is written as the item lands.

| Item | Status | Controllers | Screens |
|---|---|---|---|
| 1. Products | ✅ Done | 8 (`Product`, `Taxonomy`, `VariationTemplate`, `Warranty`, `SellingPriceGroup`, `Discount`, `Labels`, `ImportProducts`) | 14 (products index/create/edit/show/selling-prices/price-history/stock-history, variation-template ×4, labels ×2, import) |
| 2. Contacts | ✅ Done | 2 (`Contact`, `CustomerGroup`) | 7 (index, create, edit, show, ledger, opening-balance, import) |
| 3. Purchases | ✅ Done | 4 (`Purchase`, `PurchaseOrder`, `PurchaseRequisition`, `PurchaseReturn`) | 10 (purchase index/create/edit/show + shared `_form`/`_line`/`pdf`, order index/create/edit/show, requisition index/create/edit/show, return index/create/show — all served by the shared purchase views) |
| 4. Sales / POS | ✅ Done | 5 (`Sell`, `SellPos`, `SalesOrder`, `SellReturn`, `Shipment`) | 11 (sell index/create/edit/show + shared `_form`/`_line`, sales-order index/create/edit/show served by those same views, **`pos/create` — the terminal**, sell-return index/create/show, shipments index) |

**UI:** every Blade file was rebuilt against the v2 design system in three passes — shared
primitives, then the existing screens, then the outstanding sales screens authored
directly to the new standard. 69 files: 53 screens and partials, 10 components, the layout
and its 4 partials, and the published paginator. Decisions in §8.1–8.3.

**Regression guard added:** `tests/Feature/ScreensRenderTest.php` walks the route table and
renders every GET screen as an admin. New screens are covered automatically — no test to
write per item. It already caught 4 real parameter-resolution gaps.

### 10.2 What remains

Still to build, in dependency order — all of it sits on services that already exist and
are pinned by passing tests, so this is wiring, not design:

5. **Payments & finance** — `TransactionPaymentController` (→ `PaymentService`),
   `ExpenseController`, `ExpenseCategoryController`, `AccountController`,
   `CashRegisterController`.
6. **Stock** — `StockAdjustmentController`, `StockTransferController`,
   `OpeningStockController`.
7. **Reports** — `ReportController` (≈40 reports; excludes Indian GST per decision #2).
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

## 12. أمور يجب معالجتها قبل الإطلاق — Must fix before launch

Deferred deliberately, each with your approval or under decision #8, and each recorded here
so that nothing silently ships. **This section is a release gate, not a wish list.** Order is
by consequence, not by effort.

### 12.1 🔴 Cash paid out of the drawer is not counted in the shift

**Approved for deferral (your instruction), on condition it is tracked here.**

`CashRegisterService::recordableTypes()` returns sell-side documents only, so a payment to a
supplier or an expense settled in cash out of the till writes **no** drawer row. The money
physically left the drawer; the register does not know it.

*Effect on figures:* `summary()['cash_in_hand']` reads **higher** than the cash actually in
the drawer, by the total of such payments. At close, the counted denominations come in short
and the variance is attributed to the cashier. The mis-attribution is the real damage — the
arithmetic is recoverable, a cashier wrongly recorded as short is not.

*Why not done now:* `cash_register_transactions.transaction_type` is a four-value enum
(`initial | sell | transfer | refund`) with no term for money paid out. Naming that movement
touches the enum, `summary()`, the session screen and the close rail — a feature in its own
right, not a side-effect of wiring payments to the drawer.

*Why not half-done:* recording it as `transfer` would be worse than recording nothing —
`summary()` deliberately excludes transfers from `cash_in_hand`, so the figure would stay
wrong **and** the row would be filed under the wrong meaning.

*Fix, when taken:* add a fifth enum value (`payout`), teach `isDrawerMovement()` the
purchase/expense document types, subtract payouts in `summary()`, and give the close screen a
"paid out" line so the expected total is arrived at honestly rather than netted silently.
Until then, the close screen's note field is where the cashier explains the difference.

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

### 12.4 🟡 Section-structure retrofit of the pre-v2.1 screens

The 51 screens built before design system v2.1 use the v2 section rhythm. They are correct
and consistent — the tokens reached them automatically — but they do not yet use the §11.4
grouping (eyebrow headings, `.surface-quiet`, the wider section gutter) where a screen has
several distinct groups. Scheduled after item 6 and before Reports, so no screen is edited
twice.

### 12.5 🟡 `Product::scopeForLocation()` has no test coverage

The scope qualified its column with a table that does not exist (`locations.id` instead of
`business_locations.id`), which is a hard SQL error, and it survived undetected because the
only route that exercises it — `products.list` — is excluded from `ScreensRenderTest` for
being JSON with no view. Fixed at `app/Models/Product.php:68`. The **gap** is that the walk
cannot see JSON endpoints at all; a small API-response test is owed alongside the Reports
item, which adds many more of them.

