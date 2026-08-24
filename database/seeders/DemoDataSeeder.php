<?php

namespace Database\Seeders;

use App\Models\Brands;
use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\VariationTemplate;
use App\Models\VariationValueTemplate;
use App\Services\ProductService;
use App\Services\ReferenceService;
use App\Services\StockService;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A catalogue you can actually test against.
 *
 * NOT part of DatabaseSeeder — deliberately. The test suite runs
 * `migrate:fresh --seed` against `souqly_test`, and 49 products landing in that
 * database would change the counts several feature tests assert on. Demo data
 * is a development convenience, so it is opted into explicitly:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Idempotent, per entity: brands, categories, units, templates and contacts are
 * matched by name, products by name within the tenant. Re-running creates only
 * what is missing and never doubles anyone's stock — so new demo products can be
 * appended to the tables below and picked up by a second run.
 *
 * What it builds, and why each part is needed to exercise the system:
 *
 *   - 10 brands, 8 categories with 19 sub-categories — the cascading
 *     category/sub-category dropdown has nothing to cascade otherwise.
 *   - 5 units, two of them decimal (kg, litre) — quantity precision differs
 *     between a bag of rice and a phone.
 *   - 4 suppliers + 4 customers — a purchase needs a supplier, and the POS is
 *     only half tested against the walk-in customer.
 *   - 49 products: 41 `single`, 5 `variable` (18 variations between them) and
 *     3 `combo` — 62 sellable variations. All three types have separate code paths.
 *   - Stock for every stockable variation, via real `opening_stock` documents
 *     that write purchase_lines — i.e. FIFO lots, not just a cache number. Then
 *     3 supplier purchases add a *second*, dearer lot to a slice of the
 *     catalogue, so selling walks two lots and profit is actually computed.
 *
 * Combo products carry `enable_stock = 0` on purpose: a combo owns no stock, its
 * availability is derived from its components (ProductService::comboAvailableQuantity).
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Where generated thumbnails go. Read from config, not hard-coded: this is
     * the same path `Product::getImageUrlAttribute()` resolves against, and the
     * two drifting apart would silently fall back to the placeholder.
     */
    private function imageDirectory(): string
    {
        return public_path((string) config('constants.product_img_path'));
    }

    /* ====================================================================
     | Reference data
     ==================================================================== */

    private const BRANDS = [
        'جهينة' => 'ألبان وعصائر',
        'المراعي' => 'ألبان وأجبان',
        'نستله' => 'مشروبات ساخنة ومعلبات',
        'كوكاكولا' => 'مشروبات غازية',
        'بيبسي' => 'مشروبات غازية',
        'لوريال' => 'عناية شخصية',
        'بيرسيل' => 'مساحيق ومنظفات',
        'سامسونج' => 'إلكترونيات وأجهزة',
        'شاومي' => 'هواتف وملحقات',
        'تورنيدو' => 'أجهزة منزلية',
    ];

    /**
     * Parent category => sub-categories. Every parent gets a short_code so the
     * category screen has something in that column.
     */
    private const CATEGORIES = [
        'بقالة وأغذية' => ['GRC', ['معلبات', 'مكرونة وأرز', 'زيوت وسمن']],
        'مشروبات' => ['BEV', ['مشروبات غازية', 'عصائر', 'شاي وقهوة']],
        'منتجات الألبان' => ['DRY', ['حليب', 'أجبان', 'زبادي']],
        'عناية شخصية' => ['PRC', ['شامبو وصابون', 'معاجين أسنان']],
        'منظفات منزلية' => ['CLN', ['منظفات أرضيات', 'مساحيق غسيل']],
        'إلكترونيات' => ['ELC', ['هواتف وأجهزة لوحية', 'ملحقات']],
        'أجهزة منزلية' => ['APP', ['مراوح ومكيفات', 'أدوات مطبخ']],
        'ملابس وأحذية' => ['CLO', ['قمصان وتي شيرت', 'أحذية']],
    ];

    /**
     * Units keyed by the slug the product tables below refer to.
     * `pcs` is omitted — BusinessService already created it with the tenant.
     */
    private const UNITS = [
        'kg' => ['كيلوجرام', 'كجم', true],
        'ltr' => ['لتر', 'ل', true],
        'box' => ['علبة', 'علبة', false],
        'carton' => ['كرتونة', 'كرتونة', false],
    ];

    private const VARIATION_TEMPLATES = [
        'المقاس' => ['S', 'M', 'L', 'XL'],
        'مقاس الحذاء' => ['40', '41', '42', '43'],
        'اللون' => ['أبيض', 'أسود', 'أزرق', 'رمادي'],
        'المساحة التخزينية' => ['128 جيجا', '256 جيجا'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> name, business, mobile, city */
    private const SUPPLIERS = [
        ['شركة النيل للتوزيع', 'النيل للتجارة والتوزيع', '01001234567', 'القاهرة'],
        ['مؤسسة الدلتا للمواد الغذائية', 'الدلتا فودز', '01002345678', 'طنطا'],
        ['الشرق الأوسط للإلكترونيات', 'ميدل إيست إلكترونيكس', '01003456789', 'الجيزة'],
        ['مصنع الأمل للمنظفات', 'الأمل كيماويات', '01004567890', 'العاشر من رمضان'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> name, mobile, city */
    private const CUSTOMERS = [
        ['أحمد محمود', '01111234567', 'القاهرة'],
        ['سارة عبد الله', '01222345678', 'الإسكندرية'],
        ['مكتب الرياض للتوريدات', '01033456789', 'القاهرة'],
        ['محمد فتحي', '01555678901', 'المنصورة'],
    ];

    /* ====================================================================
     | Catalogue — `single` products
     ==================================================================== */

    /**
     * name, category, sub-category, brand, unit slug, purchase, sell, stock,
     * and optionally `alert` (reorder point) / `tax` (charge VAT on it).
     *
     * Prices are in EGP and roughly market-plausible, so totals on screen look
     * like a real shop's rather than like 1 + 1.
     *
     * @var array<int, array<string, mixed>>
     */
    private const PRODUCTS = [
        // بقالة وأغذية / معلبات
        ['name' => 'تونة مبروشة بالزيت 140 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'معلبات', 'brand' => 'نستله', 'unit' => 'box', 'purchase' => 38, 'sell' => 49, 'stock' => 120],
        ['name' => 'فاصوليا بيضاء معلبة 400 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'معلبات', 'brand' => null, 'unit' => 'box', 'purchase' => 18, 'sell' => 25, 'stock' => 90],
        ['name' => 'ذرة حلوة معلبة 340 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'معلبات', 'brand' => null, 'unit' => 'box', 'purchase' => 22, 'sell' => 30, 'stock' => 75],
        ['name' => 'صلصة طماطم مركزة 380 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'معلبات', 'brand' => null, 'unit' => 'box', 'purchase' => 15, 'sell' => 21, 'stock' => 160],

        // بقالة وأغذية / مكرونة وأرز
        ['name' => 'مكرونة اسباجيتي 400 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'مكرونة وأرز', 'brand' => null, 'unit' => 'pcs', 'purchase' => 12, 'sell' => 17, 'stock' => 200],
        ['name' => 'أرز مصري أبيض', 'cat' => 'بقالة وأغذية', 'sub' => 'مكرونة وأرز', 'brand' => null, 'unit' => 'kg', 'purchase' => 28, 'sell' => 36, 'stock' => 340],
        ['name' => 'شعرية 400 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'مكرونة وأرز', 'brand' => null, 'unit' => 'pcs', 'purchase' => 11, 'sell' => 15, 'stock' => 130],

        // بقالة وأغذية / زيوت وسمن
        ['name' => 'زيت ذرة 1.5 لتر', 'cat' => 'بقالة وأغذية', 'sub' => 'زيوت وسمن', 'brand' => null, 'unit' => 'ltr', 'purchase' => 105, 'sell' => 129, 'stock' => 60],
        ['name' => 'زيت زيتون بكر 500 مل', 'cat' => 'بقالة وأغذية', 'sub' => 'زيوت وسمن', 'brand' => null, 'unit' => 'ltr', 'purchase' => 180, 'sell' => 235, 'stock' => 28],
        ['name' => 'سمن نباتي 500 جم', 'cat' => 'بقالة وأغذية', 'sub' => 'زيوت وسمن', 'brand' => null, 'unit' => 'box', 'purchase' => 55, 'sell' => 69, 'stock' => 44],

        // مشروبات / مشروبات غازية
        ['name' => 'كوكاكولا 1 لتر', 'cat' => 'مشروبات', 'sub' => 'مشروبات غازية', 'brand' => 'كوكاكولا', 'unit' => 'pcs', 'purchase' => 16, 'sell' => 22, 'stock' => 240],
        ['name' => 'بيبسي 1 لتر', 'cat' => 'مشروبات', 'sub' => 'مشروبات غازية', 'brand' => 'بيبسي', 'unit' => 'pcs', 'purchase' => 15, 'sell' => 21, 'stock' => 220],
        ['name' => 'سبرايت 330 مل', 'cat' => 'مشروبات', 'sub' => 'مشروبات غازية', 'brand' => 'كوكاكولا', 'unit' => 'pcs', 'purchase' => 8, 'sell' => 12, 'stock' => 300],

        // مشروبات / عصائر
        ['name' => 'عصير مانجو 1 لتر', 'cat' => 'مشروبات', 'sub' => 'عصائر', 'brand' => 'جهينة', 'unit' => 'pcs', 'purchase' => 26, 'sell' => 34, 'stock' => 110],
        ['name' => 'عصير برتقال 1 لتر', 'cat' => 'مشروبات', 'sub' => 'عصائر', 'brand' => 'جهينة', 'unit' => 'pcs', 'purchase' => 24, 'sell' => 32, 'stock' => 95],

        // مشروبات / شاي وقهوة
        ['name' => 'شاي أسود 250 جم', 'cat' => 'مشروبات', 'sub' => 'شاي وقهوة', 'brand' => null, 'unit' => 'box', 'purchase' => 42, 'sell' => 55, 'stock' => 70],
        ['name' => 'قهوة سريعة الذوبان 200 جم', 'cat' => 'مشروبات', 'sub' => 'شاي وقهوة', 'brand' => 'نستله', 'unit' => 'box', 'purchase' => 145, 'sell' => 185, 'stock' => 36],
        ['name' => 'نسكافيه 3 في 1 عبوة 24 كيس', 'cat' => 'مشروبات', 'sub' => 'شاي وقهوة', 'brand' => 'نستله', 'unit' => 'box', 'purchase' => 88, 'sell' => 112, 'stock' => 52],

        // منتجات الألبان / حليب
        ['name' => 'حليب كامل الدسم 1 لتر', 'cat' => 'منتجات الألبان', 'sub' => 'حليب', 'brand' => 'المراعي', 'unit' => 'pcs', 'purchase' => 32, 'sell' => 41, 'stock' => 140],
        ['name' => 'حليب طويل الأجل 1 لتر', 'cat' => 'منتجات الألبان', 'sub' => 'حليب', 'brand' => 'جهينة', 'unit' => 'pcs', 'purchase' => 28, 'sell' => 36, 'stock' => 125],

        // منتجات الألبان / أجبان
        ['name' => 'جبنة رومي 250 جم', 'cat' => 'منتجات الألبان', 'sub' => 'أجبان', 'brand' => null, 'unit' => 'kg', 'purchase' => 95, 'sell' => 120, 'stock' => 30],
        ['name' => 'جبنة مثلثات 8 قطع', 'cat' => 'منتجات الألبان', 'sub' => 'أجبان', 'brand' => 'المراعي', 'unit' => 'box', 'purchase' => 34, 'sell' => 45, 'stock' => 86],
        ['name' => 'جبنة موتزاريلا 200 جم', 'cat' => 'منتجات الألبان', 'sub' => 'أجبان', 'brand' => 'المراعي', 'unit' => 'box', 'purchase' => 78, 'sell' => 98, 'stock' => 40],

        // منتجات الألبان / زبادي
        ['name' => 'زبادي طبيعي 105 جم', 'cat' => 'منتجات الألبان', 'sub' => 'زبادي', 'brand' => 'المراعي', 'unit' => 'pcs', 'purchase' => 7, 'sell' => 10, 'stock' => 180],
        ['name' => 'زبادي بالفراولة 105 جم', 'cat' => 'منتجات الألبان', 'sub' => 'زبادي', 'brand' => 'جهينة', 'unit' => 'pcs', 'purchase' => 8, 'sell' => 11, 'stock' => 150],

        // عناية شخصية / شامبو وصابون
        ['name' => 'شامبو للشعر الجاف 400 مل', 'cat' => 'عناية شخصية', 'sub' => 'شامبو وصابون', 'brand' => 'لوريال', 'unit' => 'pcs', 'purchase' => 115, 'sell' => 149, 'stock' => 48],
        ['name' => 'صابون استحمام 125 جم', 'cat' => 'عناية شخصية', 'sub' => 'شامبو وصابون', 'brand' => null, 'unit' => 'pcs', 'purchase' => 14, 'sell' => 20, 'stock' => 200],
        ['name' => 'جل استحمام 250 مل', 'cat' => 'عناية شخصية', 'sub' => 'شامبو وصابون', 'brand' => 'لوريال', 'unit' => 'pcs', 'purchase' => 68, 'sell' => 89, 'stock' => 55],

        // عناية شخصية / معاجين أسنان
        ['name' => 'معجون أسنان 100 مل', 'cat' => 'عناية شخصية', 'sub' => 'معاجين أسنان', 'brand' => null, 'unit' => 'pcs', 'purchase' => 38, 'sell' => 49, 'stock' => 90],
        // Deliberately below its reorder point, so the stock-alert report is not empty.
        ['name' => 'فرشاة أسنان متوسطة', 'cat' => 'عناية شخصية', 'sub' => 'معاجين أسنان', 'brand' => null, 'unit' => 'pcs', 'purchase' => 22, 'sell' => 32, 'stock' => 6, 'alert' => 25],

        // منظفات منزلية / منظفات أرضيات
        ['name' => 'منظف أرضيات 1 لتر', 'cat' => 'منظفات منزلية', 'sub' => 'منظفات أرضيات', 'brand' => null, 'unit' => 'ltr', 'purchase' => 42, 'sell' => 55, 'stock' => 78],
        ['name' => 'مطهر ومعطر 700 مل', 'cat' => 'منظفات منزلية', 'sub' => 'منظفات أرضيات', 'brand' => null, 'unit' => 'pcs', 'purchase' => 36, 'sell' => 48, 'stock' => 64],

        // منظفات منزلية / مساحيق غسيل
        ['name' => 'مسحوق غسيل أوتوماتيك 2.5 كجم', 'cat' => 'منظفات منزلية', 'sub' => 'مساحيق غسيل', 'brand' => 'بيرسيل', 'unit' => 'kg', 'purchase' => 185, 'sell' => 235, 'stock' => 42],
        ['name' => 'منعم أقمشة 1 لتر', 'cat' => 'منظفات منزلية', 'sub' => 'مساحيق غسيل', 'brand' => 'بيرسيل', 'unit' => 'ltr', 'purchase' => 62, 'sell' => 79, 'stock' => 58],

        // إلكترونيات / ملحقات  — taxed, to exercise the VAT path
        ['name' => 'شاحن سريع 33 وات', 'cat' => 'إلكترونيات', 'sub' => 'ملحقات', 'brand' => 'شاومي', 'unit' => 'pcs', 'purchase' => 210, 'sell' => 279, 'stock' => 34, 'tax' => true],
        ['name' => 'سماعة بلوتوث لاسلكية', 'cat' => 'إلكترونيات', 'sub' => 'ملحقات', 'brand' => 'شاومي', 'unit' => 'pcs', 'purchase' => 480, 'sell' => 649, 'stock' => 22, 'tax' => true],
        ['name' => 'باور بانك 10000 مللي أمبير', 'cat' => 'إلكترونيات', 'sub' => 'ملحقات', 'brand' => 'شاومي', 'unit' => 'pcs', 'purchase' => 520, 'sell' => 699, 'stock' => 18, 'tax' => true],
        ['name' => 'شاشة تلفزيون 43 بوصة', 'cat' => 'إلكترونيات', 'sub' => 'هواتف وأجهزة لوحية', 'brand' => 'سامسونج', 'unit' => 'pcs', 'purchase' => 9800, 'sell' => 12499, 'stock' => 7, 'alert' => 3, 'tax' => true],

        // أجهزة منزلية
        ['name' => 'مروحة حائط 16 بوصة', 'cat' => 'أجهزة منزلية', 'sub' => 'مراوح ومكيفات', 'brand' => 'تورنيدو', 'unit' => 'pcs', 'purchase' => 1250, 'sell' => 1599, 'stock' => 14, 'alert' => 5, 'tax' => true],
        ['name' => 'كتلة كهربائية 1.7 لتر', 'cat' => 'أجهزة منزلية', 'sub' => 'أدوات مطبخ', 'brand' => 'تورنيدو', 'unit' => 'pcs', 'purchase' => 640, 'sell' => 829, 'stock' => 16, 'alert' => 5],
        ['name' => 'خلاط كهربائي 500 وات', 'cat' => 'أجهزة منزلية', 'sub' => 'أدوات مطبخ', 'brand' => 'تورنيدو', 'unit' => 'pcs', 'purchase' => 890, 'sell' => 1149, 'stock' => 4, 'alert' => 8, 'tax' => true],
    ];

    /* ====================================================================
     | Catalogue — `variable` products
     ==================================================================== */

    /**
     * Each entry becomes one product with one variation group, and one variation
     * per listed value. Prices step up with size, which is what a real
     * catalogue does and what makes a per-variation price bug visible.
     *
     * @var array<int, array<string, mixed>>
     */
    private const VARIABLE_PRODUCTS = [
        [
            'name' => 'تي شيرت قطن رجالي',
            'cat' => 'ملابس وأحذية', 'sub' => 'قمصان وتي شيرت', 'brand' => null, 'unit' => 'pcs',
            'template' => 'المقاس',
            'variants' => [
                ['value' => 'S', 'purchase' => 95, 'sell' => 149, 'stock' => 18],
                ['value' => 'M', 'purchase' => 95, 'sell' => 149, 'stock' => 26],
                ['value' => 'L', 'purchase' => 98, 'sell' => 155, 'stock' => 24],
                ['value' => 'XL', 'purchase' => 102, 'sell' => 165, 'stock' => 12],
            ],
        ],
        [
            'name' => 'قميص كلاسيك رجالي',
            'cat' => 'ملابس وأحذية', 'sub' => 'قمصان وتي شيرت', 'brand' => null, 'unit' => 'pcs',
            'template' => 'المقاس',
            'variants' => [
                ['value' => 'S', 'purchase' => 180, 'sell' => 249, 'stock' => 9],
                ['value' => 'M', 'purchase' => 180, 'sell' => 249, 'stock' => 15],
                ['value' => 'L', 'purchase' => 185, 'sell' => 259, 'stock' => 13],
                ['value' => 'XL', 'purchase' => 192, 'sell' => 275, 'stock' => 7],
            ],
        ],
        [
            'name' => 'تي شيرت نسائي سادة',
            'cat' => 'ملابس وأحذية', 'sub' => 'قمصان وتي شيرت', 'brand' => null, 'unit' => 'pcs',
            'template' => 'اللون',
            'variants' => [
                ['value' => 'أبيض', 'purchase' => 85, 'sell' => 135, 'stock' => 22],
                ['value' => 'أسود', 'purchase' => 85, 'sell' => 135, 'stock' => 28],
                ['value' => 'أزرق', 'purchase' => 88, 'sell' => 139, 'stock' => 17],
                ['value' => 'رمادي', 'purchase' => 88, 'sell' => 139, 'stock' => 11],
            ],
        ],
        [
            'name' => 'حذاء رياضي رجالي',
            'cat' => 'ملابس وأحذية', 'sub' => 'أحذية', 'brand' => null, 'unit' => 'pcs',
            'template' => 'مقاس الحذاء',
            'variants' => [
                ['value' => '40', 'purchase' => 640, 'sell' => 849, 'stock' => 6],
                ['value' => '41', 'purchase' => 640, 'sell' => 849, 'stock' => 10],
                ['value' => '42', 'purchase' => 655, 'sell' => 869, 'stock' => 9],
                ['value' => '43', 'purchase' => 655, 'sell' => 869, 'stock' => 5],
            ],
        ],
        [
            'name' => 'هاتف ذكي 6.7 بوصة',
            'cat' => 'إلكترونيات', 'sub' => 'هواتف وأجهزة لوحية', 'brand' => 'شاومي', 'unit' => 'pcs',
            'template' => 'المساحة التخزينية', 'tax' => true,
            'variants' => [
                ['value' => '128 جيجا', 'purchase' => 7200, 'sell' => 8999, 'stock' => 11],
                ['value' => '256 جيجا', 'purchase' => 8400, 'sell' => 10499, 'stock' => 6],
            ],
        ],
    ];

    /* ====================================================================
     | Catalogue — `combo` products
     ==================================================================== */

    /**
     * Components are named by product; the seeder resolves each one's first
     * variation. Combo cost is the sum of its parts, so margin is meaningful.
     *
     * @var array<int, array<string, mixed>>
     */
    private const COMBO_PRODUCTS = [
        [
            'name' => 'بوكس الإفطار الاقتصادي',
            'cat' => 'بقالة وأغذية', 'sub' => null, 'brand' => null, 'sell' => 95,
            'components' => [
                ['product' => 'حليب كامل الدسم 1 لتر', 'quantity' => 1],
                ['product' => 'جبنة مثلثات 8 قطع', 'quantity' => 1],
                ['product' => 'زبادي طبيعي 105 جم', 'quantity' => 2],
            ],
        ],
        [
            'name' => 'بوكس المنظفات المنزلية',
            'cat' => 'منظفات منزلية', 'sub' => null, 'brand' => null, 'sell' => 355,
            'components' => [
                ['product' => 'مسحوق غسيل أوتوماتيك 2.5 كجم', 'quantity' => 1],
                ['product' => 'منعم أقمشة 1 لتر', 'quantity' => 1],
                ['product' => 'منظف أرضيات 1 لتر', 'quantity' => 1],
            ],
        ],
        [
            'name' => 'عرض المشروبات العائلي',
            'cat' => 'مشروبات', 'sub' => null, 'brand' => null, 'sell' => 95,
            'components' => [
                ['product' => 'كوكاكولا 1 لتر', 'quantity' => 2],
                ['product' => 'بيبسي 1 لتر', 'quantity' => 2],
                ['product' => 'سبرايت 330 مل', 'quantity' => 2],
            ],
        ],
    ];

    /* ====================================================================
     | Run
     ==================================================================== */

    /** Thumbnails to write once the transaction has committed: filename => svg. */
    private array $pendingImages = [];

    /** @var array<string, int> counters for the closing summary */
    private array $tally = [
        'brands' => 0, 'categories' => 0, 'units' => 0, 'templates' => 0,
        'contacts' => 0, 'single' => 0, 'variable' => 0, 'combo' => 0,
        'variations' => 0, 'opening_stock' => 0, 'purchases' => 0, 'lots' => 0,
    ];

    public function run(): void
    {
        $owner = User::where('username', AdminUserSeeder::USERNAME)->first();

        if (empty($owner) || empty($owner->business_id)) {
            $this->command?->error(
                'DemoDataSeeder: no "'.AdminUserSeeder::USERNAME.'" user with a business. '
                .'Run `php artisan db:seed` first — AdminUserSeeder provisions the tenant '
                .'this demo data hangs off.'
            );

            return;
        }

        // Bind the tenant explicitly: there is no session and no logged-in user
        // in a console run, so the global scopes have nothing else to read.
        Tenancy::for($owner->business_id, function () use ($owner) {
            $location = BusinessLocation::orderBy('id')->first();

            if (empty($location)) {
                $this->command?->error('DemoDataSeeder: the tenant has no business location.');

                return;
            }

            DB::transaction(fn () => $this->seed($owner, $location));

            // Written after the commit, so a rollback cannot leave orphan files.
            $this->writePendingImages();

            $this->report();
        });

        Tenancy::forget();
    }

    private function seed(User $owner, BusinessLocation $location): void
    {
        $units = $this->seedUnits($owner);
        $brands = $this->seedBrands($owner);
        [$categories, $subCategories] = $this->seedCategories($owner);
        $templates = $this->seedVariationTemplates();
        $contacts = $this->seedContacts($owner);

        $vat = TaxRate::where('name', 'VAT')->first();

        /** @var array<string, Product> products created by THIS run, keyed by name */
        $created = [];

        foreach (self::PRODUCTS as $row) {
            $product = $this->createProduct(
                $row, 'single', $owner, $units, $brands, $categories, $subCategories, $vat
            );

            if (empty($product)) {
                continue;
            }

            app(ProductService::class)->createSingleVariation($product, [
                'default_purchase_price' => $row['purchase'],
                'default_sell_price' => $row['sell'],
            ]);

            $created[$row['name']] = $product->fresh('variations');
            $this->tally['single']++;
            $this->tally['variations']++;
        }

        foreach (self::VARIABLE_PRODUCTS as $row) {
            $product = $this->createProduct(
                $row, 'variable', $owner, $units, $brands, $categories, $subCategories, $vat
            );

            if (empty($product)) {
                continue;
            }

            $template = $templates[$row['template']];

            app(ProductService::class)->createVariableVariations($product, [[
                'name' => $template['name'],
                'variation_template_id' => $template['id'],
                'variations' => array_map(fn ($variant) => [
                    'name' => $variant['value'],
                    'variation_value_id' => $template['values'][$variant['value']],
                    'default_purchase_price' => $variant['purchase'],
                    'default_sell_price' => $variant['sell'],
                ], $row['variants']),
            ]]);

            $created[$row['name']] = $product->fresh('variations');
            $this->tally['variable']++;
            $this->tally['variations'] += count($row['variants']);
        }

        // Combos come last: their components must already exist. Resolved from
        // the database rather than from $created, so a re-run that only adds a
        // combo still finds components created by an earlier run.
        foreach (self::COMBO_PRODUCTS as $row) {
            $components = [];
            $cost = 0.0;

            foreach ($row['components'] as $component) {
                $variation = $this->firstVariationOf($component['product']);

                if (empty($variation)) {
                    continue;
                }

                $components[] = [
                    'variation_id' => $variation->id,
                    'quantity' => $component['quantity'],
                ];

                $cost += (float) $variation->default_purchase_price * (float) $component['quantity'];
            }

            if (count($components) !== count($row['components'])) {
                $this->command?->warn(
                    'DemoDataSeeder: skipped combo "'.$row['name'].'" — a component product is missing.'
                );

                continue;
            }

            $product = $this->createProduct(
                $row + ['unit' => 'pcs'], 'combo', $owner, $units, $brands,
                $categories, $subCategories, $vat
            );

            if (empty($product)) {
                continue;
            }

            app(ProductService::class)->createComboVariation($product, $components, [
                'default_purchase_price' => round($cost, 2),
                'default_sell_price' => $row['sell'],
            ]);

            $this->tally['combo']++;
            $this->tally['variations']++;
        }

        $this->seedOpeningStock($created, $owner, $location);
        $this->seedPurchases($owner, $location, $contacts['suppliers']);

        unset($brands, $subCategories, $contacts);
    }

    /* ====================================================================
     | Reference data
     ==================================================================== */

    /** @return array<string, int> unit slug => id */
    private function seedUnits(User $owner): array
    {
        // The tenant already owns one unit, created with the business. Whatever
        // it is called (the locale decides), it is the default for `pcs`.
        $default = Unit::orderBy('id')->first();

        $units = ['pcs' => $default?->id];

        foreach (self::UNITS as $slug => [$name, $short, $decimal]) {
            $unit = Unit::where('actual_name', $name)->first();

            if (empty($unit)) {
                $unit = Unit::create([
                    'actual_name' => $name,
                    'short_name' => $short,
                    'allow_decimal' => $decimal,
                    'created_by' => $owner->id,
                ]);

                $this->tally['units']++;
            }

            $units[$slug] = $unit->id;
        }

        return $units;
    }

    /** @return array<string, int> brand name => id */
    private function seedBrands(User $owner): array
    {
        $brands = [];

        foreach (self::BRANDS as $name => $description) {
            $brand = Brands::where('name', $name)->first();

            if (empty($brand)) {
                $brand = Brands::create([
                    'name' => $name,
                    'description' => $description,
                    'created_by' => $owner->id,
                ]);

                $this->tally['brands']++;
            }

            $brands[$name] = $brand->id;
        }

        return $brands;
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, int>} parents, subs
     */
    private function seedCategories(User $owner): array
    {
        $parents = [];
        $subs = [];

        foreach (self::CATEGORIES as $name => [$shortCode, $children]) {
            $parent = Category::where('name', $name)->where('parent_id', 0)->first();

            if (empty($parent)) {
                $parent = Category::create([
                    'name' => $name,
                    'short_code' => $shortCode,
                    'parent_id' => 0,
                    'category_type' => 'product',
                    'created_by' => $owner->id,
                ]);

                $this->tally['categories']++;
            }

            $parents[$name] = $parent->id;

            foreach ($children as $index => $child) {
                $sub = Category::where('name', $child)->where('parent_id', $parent->id)->first();

                if (empty($sub)) {
                    $sub = Category::create([
                        'name' => $child,
                        'short_code' => $shortCode.'-'.($index + 1),
                        'parent_id' => $parent->id,
                        'category_type' => 'product',
                        'created_by' => $owner->id,
                    ]);

                    $this->tally['categories']++;
                }

                // Sub-category names are unique across the catalogue below, so a
                // flat map is enough to look one up.
                $subs[$child] = $sub->id;
            }
        }

        return [$parents, $subs];
    }

    /**
     * @return array<string, array{id: int, name: string, values: array<string, int>}>
     */
    private function seedVariationTemplates(): array
    {
        $templates = [];

        foreach (self::VARIATION_TEMPLATES as $name => $values) {
            $template = VariationTemplate::where('name', $name)->first();

            if (empty($template)) {
                $template = VariationTemplate::create(['name' => $name]);
                $this->tally['templates']++;
            }

            $valueIds = [];

            foreach ($values as $value) {
                $row = VariationValueTemplate::where('variation_template_id', $template->id)
                    ->where('name', $value)
                    ->first();

                if (empty($row)) {
                    $row = VariationValueTemplate::create([
                        'variation_template_id' => $template->id,
                        'name' => $value,
                    ]);
                }

                $valueIds[$value] = $row->id;
            }

            $templates[$name] = [
                'id' => $template->id,
                'name' => $name,
                'values' => $valueIds,
            ];
        }

        return $templates;
    }

    /** @return array{suppliers: array<int, Contact>, customers: array<int, Contact>} */
    private function seedContacts(User $owner): array
    {
        $references = app(ReferenceService::class);
        $suppliers = [];
        $customers = [];

        foreach (self::SUPPLIERS as [$name, $business, $mobile, $city]) {
            $contact = Contact::where('name', $name)->first();

            if (empty($contact)) {
                $contact = Contact::create([
                    'type' => 'supplier',
                    'name' => $name,
                    'supplier_business_name' => $business,
                    'mobile' => $mobile,
                    'city' => $city,
                    'country' => 'مصر',
                    'contact_status' => 'active',
                    'pay_term_number' => 30,
                    'pay_term_type' => 'days',
                    'created_by' => $owner->id,
                    'contact_id' => $references->generate('contact'),
                ]);

                $this->tally['contacts']++;
            }

            $suppliers[] = $contact;
        }

        foreach (self::CUSTOMERS as [$name, $mobile, $city]) {
            $contact = Contact::where('name', $name)->first();

            if (empty($contact)) {
                $contact = Contact::create([
                    'type' => 'customer',
                    'name' => $name,
                    'mobile' => $mobile,
                    'city' => $city,
                    'country' => 'مصر',
                    'contact_status' => 'active',
                    'credit_limit' => 5000,
                    'created_by' => $owner->id,
                    'contact_id' => $references->generate('contact'),
                ]);

                $this->tally['contacts']++;
            }

            $customers[] = $contact;
        }

        return ['suppliers' => $suppliers, 'customers' => $customers];
    }

    /* ====================================================================
     | Products
     ==================================================================== */

    /**
     * Create one product row, or return null when it already exists.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $units
     * @param  array<string, int>  $brands
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $subCategories
     */
    private function createProduct(
        array $row,
        string $type,
        User $owner,
        array $units,
        array $brands,
        array $categories,
        array $subCategories,
        ?TaxRate $vat
    ): ?Product {
        if (Product::where('name', $row['name'])->exists()) {
            return null;
        }

        $sku = app(ProductService::class)->generateSku();
        $image = 'demo-'.$sku.'.svg';

        $stock = $row['stock'] ?? null;

        $product = Product::create([
            'name' => $row['name'],
            'type' => $type,
            'unit_id' => $units[$row['unit'] ?? 'pcs'],
            'brand_id' => empty($row['brand']) ? null : $brands[$row['brand']],
            'category_id' => $categories[$row['cat']] ?? null,
            'sub_category_id' => empty($row['sub']) ? null : ($subCategories[$row['sub']] ?? null),
            'tax' => ! empty($row['tax']) ? $vat?->id : null,
            'tax_type' => 'exclusive',
            // A combo owns no stock of its own — its availability comes from its
            // components. Turning stock on for one would double-count.
            'enable_stock' => $type === 'combo' ? 0 : 1,
            'alert_quantity' => $row['alert'] ?? ($stock === null ? 0 : max(2, (int) round($stock * 0.1))),
            'sku' => $sku,
            'barcode_type' => 'C128',
            'image' => $image,
            'product_description' => $row['name'],
            'created_by' => $owner->id,
        ]);

        $this->pendingImages[$image] = $this->thumbnail($row['cat'], $sku);

        return $product;
    }

    /**
     * First (or only) variation of a product, looked up by product name.
     *
     * `product` is eager loaded because callers read through it. A single-row
     * `first()` would not trip `preventLazyLoading` — Laravel only arms that on
     * result sets of more than one row — so the violation would surface later,
     * somewhere else, instead of here.
     */
    private function firstVariationOf(string $productName)
    {
        return Product::where('name', $productName)
            ->first()
            ?->variations()
            ->with('product')
            ->orderBy('id')
            ->first();
    }

    /* ====================================================================
     | Stock
     ==================================================================== */

    /**
     * One `opening_stock` document per product, holding a purchase_line per
     * variation.
     *
     * Going through real documents rather than writing `qty_available` directly
     * is the whole point: a bare cache number has no lot behind it, so the first
     * sale finds nothing to consume, books zero cost and reports 100% profit.
     *
     * @param  array<string, Product>  $products
     */
    private function seedOpeningStock(array $products, User $owner, BusinessLocation $location): void
    {
        $stock = app(StockService::class);
        $openedOn = now()->subMonths(2)->startOfDay();

        foreach ($products as $name => $product) {
            if (! $product->enable_stock) {
                continue;
            }

            $quantities = $this->openingQuantitiesFor($name, $product);

            if (empty($quantities)) {
                continue;
            }

            $value = 0.0;

            foreach ($product->variations as $variation) {
                $value += ($quantities[$variation->id] ?? 0)
                    * (float) $variation->default_purchase_price;
            }

            $transaction = Transaction::create([
                'location_id' => $location->id,
                'type' => TransactionTypes::OPENING_STOCK,
                'status' => TransactionTypes::STATUS_RECEIVED,
                // Opening stock is not a debt to anyone: it is what was on the
                // shelf on day one. Leaving it `due` would invent a supplier
                // balance that no purchase screen could ever settle.
                'payment_status' => TransactionTypes::PAID,
                'opening_stock_product_id' => $product->id,
                'transaction_date' => $openedOn,
                'total_before_tax' => round($value, 4),
                'final_total' => round($value, 4),
                'created_by' => $owner->id,
            ]);

            foreach ($product->variations as $variation) {
                $quantity = (float) ($quantities[$variation->id] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                PurchaseLine::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'quantity' => $quantity,
                    'purchase_price' => $variation->default_purchase_price,
                    'purchase_price_inc_tax' => $variation->default_purchase_price,
                    'pp_without_discount' => $variation->default_purchase_price,
                    'item_tax' => 0,
                ]);

                $stock->adjustCachedQuantity(
                    $location->id, $product->id, $variation->id, $quantity
                );

                $this->tally['lots']++;
            }

            $this->tally['opening_stock']++;
        }
    }

    /**
     * Opening quantity per variation id, read back out of the catalogue tables.
     *
     * @return array<int, float>
     */
    private function openingQuantitiesFor(string $name, Product $product): array
    {
        $variations = $product->variations->sortBy('id')->values();

        // `single`: one dummy variation, one figure.
        foreach (self::PRODUCTS as $row) {
            if ($row['name'] === $name) {
                return [$variations[0]->id => (float) $row['stock']];
            }
        }

        // `variable`: the variants are in the same order they were created in.
        foreach (self::VARIABLE_PRODUCTS as $row) {
            if ($row['name'] !== $name) {
                continue;
            }

            $quantities = [];

            foreach ($row['variants'] as $index => $variant) {
                if (isset($variations[$index])) {
                    $quantities[$variations[$index]->id] = (float) $variant['stock'];
                }
            }

            return $quantities;
        }

        return [];
    }

    /**
     * Three received supplier purchases, each restocking a slice of the
     * catalogue at ~8% above the opening cost.
     *
     * The point is a *second* FIFO lot at a different price: with one lot,
     * consumption is a subtraction and any FIFO bug hides. Left `due` on
     * purpose so the purchase-payment screens have something to settle.
     *
     * @param  array<int, Contact>  $suppliers
     */
    private function seedPurchases(User $owner, BusinessLocation $location, array $suppliers): void
    {
        if (empty($suppliers) || Transaction::where('type', TransactionTypes::PURCHASE)->exists()) {
            return;
        }

        $stock = app(StockService::class);
        $references = app(ReferenceService::class);

        // Every 4th single product, split into three documents.
        $names = array_values(array_filter(
            array_column(self::PRODUCTS, 'name'),
            fn ($name, $index) => $index % 4 === 0,
            ARRAY_FILTER_USE_BOTH
        ));

        $batches = array_chunk($names, (int) ceil(count($names) / 3));

        foreach ($batches as $batchIndex => $batch) {
            $supplier = $suppliers[$batchIndex % count($suppliers)];
            $lines = [];
            $total = 0.0;

            foreach ($batch as $name) {
                $variation = $this->firstVariationOf($name);

                if (empty($variation) || ! $variation->product->enable_stock) {
                    continue;
                }

                $unitCost = round((float) $variation->default_purchase_price * 1.08, 2);
                $quantity = (float) max(5, (int) round(($variation->currentStock($location->id) ?: 20) * 0.25));

                $lines[] = ['variation' => $variation, 'quantity' => $quantity, 'cost' => $unitCost];
                $total += $quantity * $unitCost;
            }

            if (empty($lines)) {
                continue;
            }

            $transaction = Transaction::create([
                'location_id' => $location->id,
                'contact_id' => $supplier->id,
                'type' => TransactionTypes::PURCHASE,
                'status' => TransactionTypes::STATUS_RECEIVED,
                'payment_status' => TransactionTypes::DUE,
                'ref_no' => $references->generate('purchase'),
                'transaction_date' => now()->subDays(21 - ($batchIndex * 7))->setTime(10, 30),
                'total_before_tax' => round($total, 4),
                'final_total' => round($total, 4),
                'pay_term_number' => 30,
                'pay_term_type' => 'days',
                'created_by' => $owner->id,
            ]);

            foreach ($lines as $line) {
                PurchaseLine::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $line['variation']->product_id,
                    'variation_id' => $line['variation']->id,
                    'quantity' => $line['quantity'],
                    'purchase_price' => $line['cost'],
                    'purchase_price_inc_tax' => $line['cost'],
                    'pp_without_discount' => $line['cost'],
                    'item_tax' => 0,
                ]);

                $stock->adjustCachedQuantity(
                    $location->id,
                    $line['variation']->product_id,
                    $line['variation']->id,
                    $line['quantity']
                );

                $this->tally['lots']++;
            }

            $this->tally['purchases']++;
        }
    }

    /* ====================================================================
     | Thumbnails
     ==================================================================== */

    /**
     * Hue and glyph per top-level category, so the catalogue reads as a
     * catalogue at a glance instead of 48 identical placeholders.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const CATEGORY_ART = [
        'بقالة وأغذية' => [152, '<rect x="56" y="50" width="48" height="66" rx="8"/><path d="M56 70h48M56 96h48"/>'],
        'مشروبات' => [200, '<path d="M72 44h16v14l10 14v42a8 8 0 0 1-8 8h-20a8 8 0 0 1-8-8V72l10-14z"/><path d="M70 88h20"/>'],
        'منتجات الألبان' => [42, '<path d="M58 66l22-22 22 22v50H58z"/><path d="M58 66h44"/>'],
        'عناية شخصية' => [320, '<path d="M80 42c14 18 22 28 22 40a22 22 0 0 1-44 0c0-12 8-22 22-40z"/>'],
        'منظفات منزلية' => [262, '<path d="M66 66h28v50a6 6 0 0 1-6 6H72a6 6 0 0 1-6-6z"/><path d="M74 66V54h14"/><path d="M88 54l14-8"/>'],
        'إلكترونيات' => [220, '<rect x="60" y="40" width="40" height="80" rx="9"/><path d="M74 50h12"/><circle cx="80" cy="108" r="2.5"/>'],
        'أجهزة منزلية' => [14, '<rect x="54" y="58" width="52" height="40" rx="9"/><path d="M68 58V44M92 58V44M80 98v18"/>'],
        'ملابس وأحذية' => [176, '<path d="M64 48l-14 10 10 14 6-4v46h48V68l6 4 10-14-14-10h-16a10 10 0 0 1-20 0z"/>'],
    ];

    /**
     * A small, self-contained SVG thumbnail.
     *
     * Generated rather than downloaded: the seeder must work offline, and 48
     * real photographs are ~10 MB of binary in a git repository for no gain.
     * These are ~500 bytes each, share the design system's palette, and carry
     * the SKU so a screenshot is traceable back to a row.
     */
    private function thumbnail(string $category, string $sku): string
    {
        [$hue, $glyph] = self::CATEGORY_ART[$category] ?? [152, self::CATEGORY_ART['بقالة وأغذية'][1]];

        return implode('', [
            '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"',
            ' role="img" aria-label="', $sku, '">',
            '<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">',
            '<stop offset="0" stop-color="hsl(', $hue, ' 60% 96%)"/>',
            '<stop offset="1" stop-color="hsl(', $hue, ' 48% 88%)"/>',
            '</linearGradient></defs>',
            '<rect width="160" height="160" rx="16" fill="url(#g)"/>',
            '<rect x="0.5" y="0.5" width="159" height="159" rx="15.5" fill="none"',
            ' stroke="hsl(', $hue, ' 45% 62%)" stroke-opacity="0.55"/>',
            '<g fill="none" stroke="hsl(', $hue, ' 55% 34%)" stroke-opacity="0.7" stroke-width="4.5"',
            ' stroke-linecap="round" stroke-linejoin="round">', $glyph, '</g>',
            '<text x="80" y="142" text-anchor="middle" font-family="ui-monospace, monospace"',
            ' font-size="12" fill="hsl(', $hue, ' 40% 34%)" fill-opacity="0.6">', $sku, '</text>',
            '</svg>',
        ]);
    }

    private function writePendingImages(): void
    {
        if (empty($this->pendingImages)) {
            return;
        }

        $directory = $this->imageDirectory();

        File::ensureDirectoryExists($directory);

        foreach ($this->pendingImages as $filename => $svg) {
            File::put($directory.DIRECTORY_SEPARATOR.$filename, $svg);
        }
    }

    /* ====================================================================
     | Report
     ==================================================================== */

    private function report(): void
    {
        if (array_sum($this->tally) === 0) {
            $this->command?->warn(
                'DemoDataSeeder: nothing to do — the demo catalogue is already seeded.'
            );

            return;
        }

        $this->command?->info('DemoDataSeeder: created');
        $this->command?->table(
            ['ما تم إنشاؤه', 'العدد'],
            [
                ['براندات (brands)', $this->tally['brands']],
                ['فئات وفئات فرعية (categories)', $this->tally['categories']],
                ['وحدات قياس (units)', $this->tally['units']],
                ['قوالب متغيرات (variation templates)', $this->tally['templates']],
                ['جهات اتصال: موردون وعملاء (contacts)', $this->tally['contacts']],
                ['منتجات single', $this->tally['single']],
                ['منتجات variable', $this->tally['variable']],
                ['منتجات combo', $this->tally['combo']],
                ['إجمالي المتغيرات القابلة للبيع (variations)', $this->tally['variations']],
                ['مستندات رصيد افتتاحي (opening stock)', $this->tally['opening_stock']],
                ['فواتير مشتريات (purchases)', $this->tally['purchases']],
                ['دفعات مخزون FIFO (lots)', $this->tally['lots']],
            ]
        );
    }
}
