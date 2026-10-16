# عقد SEO بين الباك والفرونت

**تاريخ المراجعة:** 16 أكتوبر 2026

**نسخة API:** `/api/v1`
**حالة المصدر:** شجرة العمل الحالية غير نظيفة، لذلك لا أنسب هذا العقد إلى commit منشور. `HEAD` الحالي `9abcc91` ولا يتضمن وحده كل الملفات المعدلة أو غير المتتبعة التي راجعتها.

هذا المستند يصف ما يفعله كود Laravel الحالي، ويفصل بينه وبين طلب الفرونت وملاحظاته عن نشر سابق. طلب الفرونت وثيقة متطلبات للتسليم، وليس دليلًا على أن endpoint مقترح موجود. ملاحظات 503 و404 في نشر staging/production تخص ذلك النشر القديم، ولا تثبت حالة هذه الشجرة.

ملف PDF موجود باسم `F:\Downloads\SEO Architecture & Implementation Specification.pdf`؛ أما اسم النسخة `(1).pdf` الذي ظهر في المراجعة السابقة فلم أجده. أمكن قراءة بعض البنية والعناوين من PDF الأصلي، لكن لا أملك استخراجًا كاملًا موثوقًا لكل نصه. لذلك لا أدعي تدقيق كل عبارة فيه؛ عند المقارنة اعتمدت كذلك على الأقسام المشار إليها في الطلب والعقد المشترك، مع تمييز النواقص المثبتة من متطلبات لم يتسن تأكيد نصها الكامل.

## حالة التحقق ومصدر الأمثلة

الحالات المستخدمة:

- **منفذ وله اختبار موجود:** endpoint أو السلوك موجود، ويوجد اختبار مرتبط به في الشجرة. لم أشغّل الاختبارات في هذه المراجعة لأن PHP غير متاح في بيئة الأوامر.
- **منفذ، غير متحقق منه:** الكود موجود، لكن لم أجد تغطية مباشرة كافية أو لم يمكن التحقق من التنفيذ الحالي.
- **غير منفذ:** لا يوجد endpoint أو عقد مطابق في المسارات الحالية.

أمثلة JSON أدناه **توضيحية وغير متحقق منها بالتشغيل**؛ بُنيت من أسماء الحقول والـfixtures الموجودة في الاختبارات، ونُقحت لتجنب كشف بيانات. لا تمثل استجابة من deployment، ولا نتائج اختبار حالية. الاختبارات المرجعية أسماء ملفات واختبارات موجودة في الشجرة، لا نتائج تشغيل جديدة. لا يوجد commit منشور أستطيع ربط هذه الأمثلة به.

طلب الفرونت يذكر أن بعض اختبارات TypeScript نجحت وأن نشرًا سابقًا أعاد أخطاء؛ هذه معلومات واردة في الطلب وليست تحققًا أجريته على الباك.

## اللغة والاستجابة والكاش

- واجهات v1 ضمن `SetApiLocale`. يقرأ `Accept-Language`، يقبل `ar` أو `en`، ويرجع افتراضيًا إلى `ar`. الاستجابة تحمل `Content-Language` و`Vary: Accept-Language`.
- صفحات المنتج والقسم والمحتوى تستخدم **slug واحدًا** للغتين؛ اللغة تُختار من header. لا يوجد في هذه الواجهات دليل على slugs مستقلة لكل لغة.
- أغلب استجابات الموارد تكون داخل `{ "data": ... }`. أخطاء التحقق المعتادة 422، والضيف عن مسار الإدارة 401، والمستخدم الموثق الذي لا يملك `admin.access` يحصل على 403، والكيان/المسار غير الموجود 404.
- لم أجد سياسة `Cache-Control` عامة محددة لهذه الواجهات. لا توثقها على أنها `no-store` أو قابلة للتخزين اعتمادًا على هذا الكود وحده؛ إعدادات proxy/CDN خارج الشجرة. `Vary` على اللغة مهم لأي طبقة تخزين مشتركة.
- Resolver التحويلات يخزن lookup الإيجابي والسلبي داخليًا لمدة 600 ثانية، ثم يعيد التحقق من ظهور المصدر والوجهة ومن عدم وجود سلسلة تحويل قبل إرجاع النتيجة. هذا ليس تحويل HTTP ولا سياسة cache لواجهة المتجر.

أمثلة أخطاء شائعة توضيحية: تحقق 422 يعيد `message` و`errors` بحسب Laravel؛ guest على endpoint إداري يعيد 401، ومستخدم موثق بلا صلاحية يعيد 403. لا توجد fixture حديثة ملتقطة لهذه الردود في هذا المستند.

```json
{
  "message": "The given data was invalid.",
  "errors": { "limit": ["The selected limit is invalid."] }
}
```

الدليل: `routes/api.php`; `app/Http/Middleware/SetApiLocale.php`; `app/Services/SeoRedirectService.php`.

## 1. الكتالوج: GET `/api/v1/products`

**الحالة:** منفذ وله اختبارات API عامة؛ الاختبارات لم تُشغّل في هذه المراجعة.

### المدخلات والسلوك الفعلي

| Query | النوع والحد | التنفيذ |
|---|---|---|
| `q` | نص، حتى 100 محرف، nullable | بحث نصي في الاسم العربي والإنجليزي والـslug؛ الكلمات بعد التطبيع تُطبّق جميعها، وكل كلمة تطابق أحد الحقول. |
| `category` | slug نصي، حتى 255 محرف، nullable | يطابق قسمًا عامًا ويشمل القسم وأحفاده المرئيين. slug غير الموجود/غير المرئي يعطي نتيجة فارغة. |
| `page` | عدد صحيح ≥ 1 | pagination بعد `q` و`category`. الافتراضي Laravel هو 1. |
| `per_page` | عدد صحيح 1–100 | pagination بعد الفلاتر. الافتراضي 24. |

الترتيب ثابت: `published_at DESC, id DESC`. مؤهلات visibility تُطبّق على query قبل pagination. لا توجد هنا فلاتر `min_price` أو `max_price` أو `color` أو `offer` أو `collection`، ولا `sort` بمدخلات `price_asc` أو `price_desc` أو `popular`. القيم غير الموجودة في قواعد التحقق لا تُحوّل إلى هذه السلوكيات؛ كود endpoint لا يرفض صراحةً كل query إضافي، لذا لا تعتمد على تمريرها أو على خطأ تحقق لها.

`price` في بطاقة المنتج هو السعر المعروض للتنوع الذي اختارته قواعد المنتج، بعد العرض الفعلي إذا انطبق؛ السعر الأصلي/حالة العرض حقول منفصلة. لا يوجد سعر نطاق مستخدم لفلترة القائمة. اختيار تنوع العرض لا يجعل المنتج النافد مخفيًا: توجد تغطية صريحة بأن المنتج المرئي النافد يبقى ظاهرًا ومؤهلًا وفق بقية قواعد SEO.

Laravel pagination يعيد metadata للنتيجة بعد الفلاتر، ومنها `total`, `last_page`, `current_page`, `per_page`, `from`, `to`. الصفحة خارج النطاق تكون صفحة بيانات فارغة مع metadata الصفحة المطلوبة؛ لم أجد سياسة خاصة لإعادتها كـ404. بعد اكتمال الصفحة قد تكون `from` و`to` فارغتين.

### مثال توضيحي غير متحقق منه

```http
GET /api/v1/products?category=blankets&page=2&per_page=24
Accept-Language: ar
```

```json
{
  "data": [
    {
      "id": "101",
      "slug": "winter-blanket",
      "name": "بطانية شتوية",
      "category": { "slug": "blankets", "name": "البطانيات" },
      "displayVariantId": "501",
      "price": 125.5,
      "originalPrice": null,
      "offerApplied": false,
      "offerId": null,
      "discountPercentage": null,
      "badge": null,
      "inStock": true,
      "image": null
    }
  ],
  "links": { "first": "…", "last": "…", "prev": "…", "next": "…" },
  "meta": {
    "current_page": 2,
    "from": 25,
    "last_page": 4,
    "per_page": 24,
    "to": 48,
    "total": 80
  }
}
```

نفس slug يُستخدم مع `Accept-Language: en`; الاسم المحلي يتغير، لكن لا يوجد slug إنجليزي منفصل بعقد هذا endpoint.

**اختبارات مرجعية:** `tests/Feature/Api/ProductApiTest.php`, `tests/Feature/Api/ProductOffersPresentationTest.php`, `tests/Feature/Api/SeoPublicPayloadTest.php::test_zero_stock_does_not_hide_a_visible_product_or_make_it_ineligible`.

**النتيجة للفرونت:** إزالة الفلترة المحلية على صفحة واحدة فقط لا تكفي لإنشاء فلاتر الكتالوج المطلوبة. قبل تنفيذ فلاتر السعر/اللون/العروض/المجموعة أو ترتيب `popular`، يلزم قرار وتعريف backend مستقل يشمل معنى السعر ومفتاح كسر التعادل. حد الـ5,000 URL في الفرونت لا ينشئ endpoint أو shard API تلقائيًا.

## 2. إعدادات SEO العامة وبيانات المتجر

### إعدادات الإدارة

**منفذة ولها اختبارات موجودة:**

- `GET /api/v1/admin/seo/settings`
- `PUT /api/v1/admin/seo/settings`
- الحماية: `auth:sanctum` ثم `admin.access`.
- GET يعيد `{data: [{key, locale, value}, ...]}` للسجلات المخزنة؛ `locale: null` للإعداد العام. هذه قيم الإدارة المخزنة، وليست metadata عامة محلولة.
- PUT يقبل `{settings: [{key, locale?, value}, ...]}`، بحد أقصى 50 عنصرًا في الطلب. المفتاح يجب أن يكون ضمن catalog. الإعداد المحلي يتطلب `locale: "ar"` أو `"en"`؛ الإعداد العام لا يحتاج locale. `value: null` يحذف/يمسح القيمة المخصصة؛ عدم إرسال المفتاح لا يغيره. التحديث جزئي.
- إعدادات العناوين والوصف واسم المتجر محلية اللغة؛ الشعار وصورة OG الافتراضية والدومين الأساسي واللغات المدعومة وrobots عامة. لا يوجد تخصيص SEO مستقل لـ`home` و`shop` و`contact` في catalog أو endpoint الحالي.
- endpoint الإدارة ليس مصدرًا عامًا، ولا ينبغي تمرير استجابته مباشرة إلى metadata العامة.

أهم المفاتيح الفعلية: `storeName`, `defaultSeoTitle`, `defaultMetaDescription`, `defaultOgImageId`, `storeLogoId`, `canonicalBaseUrl`, `supportedLocales`, `defaultRobotsPolicy`.

### Endpoint المتجر العام

**`GET /api/v1/store/structured-data` منفذ وله اختبار موجود.** هو مصدر عام لهوية المتجر/Organization وWebSite وبعض بيانات التواصل والسوشيال والسياسات، وليس endpoint لحل SEO الرئيسي أو صفحة المتجر أو صفحة الاتصال.

العقد يعيد داخل `data`:

- `organization`: `organizationId`, `name`, `url`, `logo`, `contact`, `sameAs`.
- `website`: `websiteId`, `name`, `url`, `inLanguage`, `publisherId`.
- `currency` من `config/store.php` (حاليًا EGP).
- `policies.returns` و`policies.shipping` كمراجع صفحات؛ قد تكون كل واحدة `null` إذا لم يتوفر المرجع.

للبيانات العامة أيضًا مساران منفصلان `GET /api/v1/store/contact` و`GET /api/v1/store/social-links`. endpoint بيانات المتجر يعيد بيانات التواصل العامة الصالحة وروابط السوشيال المفعلة من المنصات المقبولة، ولا يعيد أسرار أو إعدادات الإدارة. يوجد الآن endpoint SEO عام مستقل للصفحات الثابتة home وshop وcontact.

### Public `GET /api/v1/seo/settings`

**منفذ في الكود؛ الاختبارات أضيفت ولم يمكن تشغيلها في بيئة العمل الحالية لغياب PHP من PATH.** المسار `GET /api/v1/seo/settings` عام ولا يحتاج token. يقبل `Accept-Language` عبر `SetApiLocale`، ويعيد `Content-Language` و`Vary: Accept-Language`. اللغتان المدعومتان `ar` و`en`؛ قيمة `fr-FR` تتبع convention بقية API وترجع العربية. لا يقرأ endpoint locale من query string، ويرفض query parameters غير المعروفة بـ422.

القيم canonical ثابتة ومسارات الموقع النسبية: `home` إلى `/`، و`shop` إلى `/shop`، و`contact` إلى `/contact`. لا يضيف الباك domain أو بادئة locale؛ الفرونت يركبهما. كل صفحة تعيد عقد SEO العام نفسه: `title` و`description` و`canonicalPath` و`robots.index/follow` و`openGraph.title/description/image` و`indexable` و`availableLocales` و`lastSignificantModifiedAt`. النص والصورة غير المتاحين يرجعان `null` مع بقاء المفتاح حاضرًا. `availableLocales` يأتي من `supportedLocales` أو `['ar','en']` إذا لم يُضبط. `lastSignificantModifiedAt` يأتي من أوقات إعدادات الصفحة والـfallbackات المستخدمة وسجل SEO للـsetting والصور المستخدمة؛ لا يُستخدم وقت GET.

ترتيب الحل: عنوان الصفحة المحلي المخصص → `defaultSeoTitle` للغة نفسها → `storeName` للغة نفسها → `null`. الوصف المحلي المخصص → `defaultMetaDescription` للغة نفسها → `null`. لا يوجد fallback بين اللغتين. OG title والوصف يساويان الناتج المحلول لكل منهما. صورة OG المخصصة الصالحة وفق `SeoPublicImageService` → `defaultOgImageId` العام الصالح → `null`. سياسة robots الخاصة بالصفحة تحفظ `false` صراحةً، والقيم `null` ترجع إلى `defaultRobotsPolicy`؛ بعدها تطبق بوابة `SEO_INDEXING_ALLOWED` ووجود اللغة على `robots.index` و`indexable`.

لا يوجد cache في الخدمة ولا `Cache-Control` خاص بالendpoint؛ الوسيط يضيف `Vary` و`Content-Language` فقط، لذا يتبع التخزين الوسيط/العميل إعداداته. لا يعيد public payload IDs أو مفاتيح settings أو قيمًا خامًا أو بيانات audit/إدارة.

إعدادات الإدارة تستخدم المسارين الحاليين المحميين بـ`auth:sanctum` و`admin.access`: `GET /api/v1/admin/seo/settings` و`PUT /api/v1/admin/seo/settings`. مفاتيح الصفحات الثابتة هي `homeSeoTitle`, `homeMetaDescription`, `homeRobotsPolicy`, `homeOgImageId`، وما يقابلها من `shop` و`contact`. العنوان والوصف يقبلان locale صريحًا؛ robots والصورة إعدادان عامان للصفحة ويُخزنان بـlocale فارغ. الحقل الغائب لا يغيّر القيمة، و`null` يمسح التخصيص، و`false` قيمة صريحة. الصور تتحقق من الوسيط العام الموجود. لا تقبل الواجهة أسماء صفحات أو مسارات يحددها المستخدم.

مثال كتابة عربية وإنجليزية عبر PUT جزئي لإعدادات SEO الحالية (يحفظ العناصر المذكورة فقط):

```json
{
  "settings": [
    {"key": "homeSeoTitle", "locale": "ar", "value": "الرئيسية | Shiny & Style"},
    {"key": "homeMetaDescription", "locale": "ar", "value": "تعرّفي على أحدث منتجات المتجر."},
    {"key": "homeSeoTitle", "locale": "en", "value": "Home | Shiny & Style"},
    {"key": "homeRobotsPolicy", "value": {"index": false, "follow": true}}
  ]
}
```

بعد نجاح الحفظ، القراءة العامة بـ`Accept-Language: ar` أو `en` تعيد `data.pages.home` محلولًا بعد fallback؛ لن يظهر `homeSeoTitle` أو أي row ID في الاستجابة العامة. إرسال `{"key":"homeSeoTitle","locale":"ar","value":null}` يمسح التخصيص العربي ويرجع إلى `defaultSeoTitle` العربي ثم `storeName` العربي. لا يؤثر ذلك في الإنجليزية. الصلاحية الناقصة على الإدارة تعيد 401 للزائر و403 للمستخدم غير المصرح؛ payload غير صالح أو query غير معروفة يعيدان 422 بعقد أخطاء API الحالي.

مثال استجابة عربية sanitized:

```json
{
  "data": {
    "locale": "ar",
    "pages": {
      "home": {
        "title": "الرئيسية | Shiny & Style",
        "description": "تعرّفي على أحدث منتجات المتجر.",
        "canonicalPath": "/",
        "robots": {"index": false, "follow": true},
        "openGraph": {"title": "الرئيسية | Shiny & Style", "description": "تعرّفي على أحدث منتجات المتجر.", "image": null},
        "indexable": false,
        "availableLocales": ["ar", "en"],
        "lastSignificantModifiedAt": "2026-10-16T12:00:00.000000Z"
      },
      "shop": {"title": null, "description": null, "canonicalPath": "/shop", "robots": {"index": false, "follow": true}, "openGraph": {"title": null, "description": null, "image": null}, "indexable": false, "availableLocales": ["ar", "en"], "lastSignificantModifiedAt": null},
      "contact": {"title": null, "description": null, "canonicalPath": "/contact", "robots": {"index": false, "follow": true}, "openGraph": {"title": null, "description": null, "image": null}, "indexable": false, "availableLocales": ["ar", "en"], "lastSignificantModifiedAt": null}
    }
  }
}
```

مثال إنجليزي sanitized مع fallback وعدم وجود تخصيص إنجليزي مستقل:

```json
{
  "data": {
    "locale": "en",
    "pages": {
      "home": {
        "title": "Default store title",
        "description": "Default store description",
        "canonicalPath": "/",
        "robots": {"index": true, "follow": true},
        "openGraph": {"title": "Default store title", "description": "Default store description", "image": "https://cdn.example.test/media/default-og.webp"},
        "indexable": true,
        "availableLocales": ["ar", "en"],
        "lastSignificantModifiedAt": "2026-10-16T12:00:00.000000Z"
      },
      "shop": {"title": "Default store title", "description": "Default store description", "canonicalPath": "/shop", "robots": {"index": true, "follow": true}, "openGraph": {"title": "Default store title", "description": "Default store description", "image": "https://cdn.example.test/media/default-og.webp"}, "indexable": true, "availableLocales": ["ar", "en"], "lastSignificantModifiedAt": "2026-10-16T12:00:00.000000Z"},
      "contact": {"title": "Default store title", "description": "Default store description", "canonicalPath": "/contact", "robots": {"index": true, "follow": true}, "openGraph": {"title": "Default store title", "description": "Default store description", "image": "https://cdn.example.test/media/default-og.webp"}, "indexable": true, "availableLocales": ["ar", "en"], "lastSignificantModifiedAt": "2026-10-16T12:00:00.000000Z"}
    }
  }
}
```

الأمثلة توضيحية sanitized وليست snapshots من تشغيل ناجح. الاختبارات المضافة في `PublicSeoSettingsApiTest` تغطي القراءة العامة واللغتين والـfallback والحفظ والمسح وrobots والصور وعدم الكتابة وno-op؛ نتيجة التشغيل غير متاحة لأن PHP غير مثبت/غير موجود في PATH. اختبار الإدارة الموجود `AdminSeoApiTest::test_settings_are_fixed_typed_partial_and_global_values_have_one_row` يغطي العقد الأساسي للمفاتيح الثابتة والصلاحيات. لا توجد response cache headers خاصة؛ اختبار الحفظ الفعلي → القراءة مغطى كحالة feature لكن لم يمكن تأكيد نتيجته محليًا.

### الفرق بين المخزن والمحلول

- في PUT إعدادات أو SEO: الحقل الغائب يعني «اترك المخزن كما هو»؛ `null` يعني «امسح المخصص» عند قبول الحقل.
- في GET إعدادات الإدارة: السجل غير المخزن لا يظهر كسجل مُنشأ افتراضيًا.
- في استجابة صفحة عامة: `seo.title`, `description`, `canonicalPath`, `robots`, `openGraph` هي نتيجة resolver بعد fallback، وليست انعكاسًا مباشرًا للمخصص. المفاتيح موجودة في payload حتى لو كانت القيمة `null`.
- أولوية العنوان: SEO مخصص → عنوان المحتوى المحلي → `defaultSeoTitle` المحلي → `storeName` المحلي. الوصف: SEO مخصص → وصف/محتوى الصفحة بعد تنظيفه → `defaultMetaDescription` للغة نفسها. OG title/description يرثان من metadata المحلولة عند غياب المخصص.
- `robots.index=false` أو عدم توفر اللغة يجعل `indexable=false` ولا يحول الصفحة العامة تلقائيًا إلى 404.

### مثالان توضيحيان غير متحقق منهما

```json
{
  "data": {
    "seo": {
      "title": "عنوان عربي مخصص",
      "description": "وصف عربي بعد fallback",
      "canonicalPath": "/products/winter-blanket",
      "robots": { "index": true, "follow": true },
      "openGraph": {
        "title": "عنوان عربي مخصص",
        "description": "وصف عربي بعد fallback",
        "image": null
      },
      "indexable": true,
      "availableLocales": ["ar", "en"],
      "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
    }
  }
}
```

```json
{
  "data": {
    "seo": {
      "title": "English product",
      "description": null,
      "canonicalPath": "/products/winter-blanket",
      "robots": { "index": false, "follow": true },
      "openGraph": { "title": "English product", "description": null, "image": null },
      "indexable": false,
      "availableLocales": ["ar"],
      "lastSignificantModifiedAt": null
    }
  }
}
```

المثال الثاني يصف نتيجة resolver للغة غير متاحة إذا أمكن الوصول إلى حالة البيانات هذه. لا يعني أن route يحجب الصفحة. اختبار غياب ترجمة المنتج يستخدم حالة غير محفوظة لأن migration وقواعد حفظ المنتج الحالية تمنع الحالة المقابلة. أما صفحات المحتوى فـ`PUT` يتطلب العنوان والجسم باللغتين، غير فارغة.

## 3. تفاصيل المنتج والقسم

### المنتج العام

`GET /api/v1/products/{slug}` عام. 404 عندما لا يطابق المنتج قواعد الظهور العامة. اسم المنتج ووصفه localized مع fallback المحتوى الحالي. الاستجابة تحتفظ بحقول التجارة الحالية وتضيف `seo` و`structuredData.product` و`structuredData.breadcrumbs`.

عقد `structuredData.product` يشمل معرف المنتج، الاسم والوصف، صورًا، `canonicalPath`، `displayVariantId`، `offer` للتنوع المعروض، `variants`, `variationAttributes`, و`productGroup` إن أمكن، و`seller` إن كانت هوية المتجر متاحة. `offer.sku` هو SKU فعلي وقد يكون `null`؛ لا يستخدم ID المنتج بديلًا عنه. السعر من العرض الساري عند انطباقه، والعملة EGP، والتوفر من المتاح بعد الحجوزات. نفاد المخزون لا يحجب المنتج. بيانات المتجر المنظمة و`structuredData.product.seller` تشتركان في مصدر الهوية.

### القسم العام

`GET /api/v1/categories/{slug}` عام؛ القسم المخفي أو الذي له أب غير مرئي لا يظهر ويعيد 404 عند طلب التفاصيل. تفاصيل القسم تحوي `seo`, `structuredData.breadcrumbs`، و`breadcrumbs`/الأبناء الحالية. visibility منفصلة عن indexability.

### مثال منتج/قسم توضيحي غير متحقق منه

```json
{
  "data": {
    "id": "101",
    "slug": "winter-blanket",
    "name": "Winter blanket",
    "seo": {
      "title": "Winter blanket",
      "description": "Soft winter blanket",
      "canonicalPath": "/products/winter-blanket",
      "robots": { "index": true, "follow": true },
      "indexable": true,
      "availableLocales": ["ar", "en"],
      "openGraph": { "title": "Winter blanket", "description": "Soft winter blanket", "image": null },
      "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
    },
    "structuredData": {
      "product": {
        "productId": "101",
        "displayVariantId": "501",
        "offer": {
          "sellableItemId": "501",
          "sku": "CURRENT-SKU",
          "offerId": null,
          "offerApplied": false,
          "price": 125.5,
          "priceCurrency": "EGP",
          "availability": "InStock"
        }
      },
      "breadcrumbs": []
    }
  }
}
```

ملاحظة: هذا مقتطف بنيوي؛ الاستجابة الفعلية تتضمن حقول تفاصيل المنتج الأخرى، و`structuredData.product` يحتوي حقول الصور والتنوعات والـseller حسب البيانات المتاحة. المسار canonical منطقي بلا لغة/دومين؛ على الفرونت تركيبهما مرة واحدة. أسماء الأقسام تتغير مع اللغة، والاختيار يتبع القسم الأساسي الحالي.

**اختبارات مرجعية:** `tests/Feature/Api/SeoPublicPayloadTest.php`, `tests/Feature/Api/ProductOffersPresentationTest.php`, `tests/Feature/Api/StoreStructuredDataApiTest.php`.

## 4. صفحات المحتوى والتحرير

**حالة الإضافات في هذا التسليم:** إضافة ID مورد الإدارة وتنفيذ تحويلات slug القسم. يوجد اختبار مباشر لاستقلال SEO حسب اللغة وحفظ المحتوى عن SEO؛ نتائج التشغيل غير متاحة لأن PHP غير موجود في PATH.

### إدارة المحتوى الحالي

| Method/Path | الحماية | الشكل والسلوك |
|---|---|---|
| `GET /api/v1/admin/content-pages` | `auth:sanctum`, `admin.access` | `data` قائمة الصفحات المضبوطة. كل مورد يعيد `id`, `slug`, `titleAr`, `titleEn`, `bodyAr`, `bodyEn`, `createdAt`, `updatedAt`. يكون `id: null` للصفحة المضبوطة التي لم تُحفظ بعد، ويصبح ID قاعدة البيانات بعد أول حفظ. |
| `GET /api/v1/admin/content-pages/{slug}` | الحماية نفسها | المورد نفسه؛ `{slug}` يظل مفتاح المسار، والـslug غير المدعوم 404. صفحة مضبوطة غير محفوظة تعيد `id: null`. |
| `PUT /api/v1/admin/content-pages/{slug}` | الحماية نفسها | يتطلب `titleAr`, `titleEn`, `bodyAr`, `bodyEn`، كل حقل نص غير فارغ؛ العنوان حتى 255 والجسم حتى 50000. يحفظ اللغتين معًا، لا PATCH/حفظ محتوى لكل لغة منفردة. |

### إدارة SEO لصفحة المحتوى

المساران الموجودان هما:

- `GET /api/v1/admin/seo/content_page/{id}`
- `PUT /api/v1/admin/seo/content_page/{id}`

الحماية نفسها؛ `{id}` رقم قاعدة البيانات. alias المقبول هو **`content_page`**، وليس `page`. `page` هو اسم نوع Sitemap/حدث revalidation، وليس alias إدارة SEO. `SeoTargetService` لا يقبل `page` في إدارة السجل.

GET يعيد `data` كمصفوفة سجلات SEO الموجودة، كل سجل حسب locale. لا يوجد سجل مخزن؟ `data: []`. PUT يحدد locale في body ويقبل تعديلًا جزئيًا من `metaTitle`, `metaDescription`, `canonicalPath`, `robots.index`, `robots.follow`, `openGraph.title`, `openGraph.description`, `openGraph.imageId`. الحقل المحذوف من PUT يبقى كما هو؛ `null` يمسح المخصص. إذا أزيل آخر تخصيص، تكون استجابة PUT `{ "data": null }`. حقول المسار المخصص يجب أن تطابق canonical الذي ينتجه mapping للكيان.

SEO والمحتوى عمليتان مستقلتان: مسار حفظ المحتوى لا يحفظ سجل SEO، ومسار SEO لا يعدل عنوان/جسم المحتوى. حفظ المحتوى يتطلب إرسال القيم الأربع في كل مرة؛ حفظ SEO يمكن أن يكون جزئيًا لكل لغة.

مثالان آمنان على حفظ SEO جزئيًا للصفحة ذات `id: 42`:

```http
PUT /api/v1/admin/seo/content_page/42
Content-Type: application/json

{"locale":"ar","metaTitle":"عنوان مخصص","robots":{"index":false}}
```

يحفظ هذا الحقول المرسلة للعربية فقط؛ الحقول المحذوفة تبقى كما هي. لمسح عنوان مخصص أرسل `null` صراحةً:

```http
PUT /api/v1/admin/seo/content_page/42
Content-Type: application/json

{"locale":"en","metaTitle":null,"openGraph":{"imageId":null}}
```

مثال استجابة نجاح مختصر:

```json
{"data":{"locale":"ar","metaTitle":"عنوان مخصص","metaDescription":null,"canonicalPath":null,"robots":{"index":false,"follow":null},"openGraph":{"title":null,"description":null,"imageId":null}}}
```

يجب استخدام `id` الذي أعاده مورد الإدارة؛ لا يُستنتج من slug. إذا كان `id` فارغًا، احفظ المحتوى أولًا عبر endpoint المحتوى الحالي ثم استخدم ID الاستجابة. guest يعيد 401، المستخدم بلا صلاحية الإدارة 403، وID غير موجود أو alias غير مدعوم مثل `page` يعيد 404. بيانات غير صالحة، locale غير مدعوم، أو canonical لا يطابق mapping يعيد 422 مع `errors`. هذه المسارات تحت `auth:sanctum` و`admin.access`.

### الصفحات العامة والترجمة

`GET /api/v1/content-pages/{slug}`. 404 للـslug غير المدعوم، أو عدم وجود سجل محتوى محفوظ. إذا وجد السجل لكن حقل اللغة المطلوبة فارغ/غير متاح، الـcontroller لا يفرض 404 لمجرد غياب الترجمة؛ المورد يعيد `title`/`body` للغة الحالية كما هي (`null` عند غيابها)، وتعرض `seo.availableLocales` اللغات الفعلية و`indexable` يكون false للغة غير المتاحة. العقد الطبيعي الحالي يحمي من إنشاء هذه الحالة لأن PUT يتطلب تعبئة اللغتين.

الاستجابة العامة الحالية تحتوي `title`, `body`, و`seo`. **لا تحتوي `structuredData` لصفحات المحتوى**، ولا تضيف `indexable` أو `availableLocales` في مستوى أعلى من `seo`.

### mapping المسارات المؤكد

| Backend slug | مسار الموقع المنطقي | الحالة |
|---|---|---|
| `about-us` | `/about` | مؤكد ومستخدم |
| `returns-policy` | `/return-policy` | مؤكد ومستخدم |
| `shipping-policy` | `/shipping-policy` | مؤكد ومستخدم |
| `privacy-policy` | لا يوجد mapping حالي | لا تخمّن؛ يلزم تأكيد المسار |

`terms` غير موجود في قائمة slugs المضبوطة حاليًا. لا تُستنتج مسارات زوار من slug الـbackend.

**الحالة الحالية:** موارد إدارة المحتوى تعيد ID قاعدة البيانات الثابتًا بعد حفظ الصفحة. الصفحات المضبوطة غير المحفوظة تعيد `id: null` حتى لا ينشئ GET سجلًا. لا ينبغي للفرونت استنتاج CMS ID من slug. لا توجد `structuredData` عامة لصفحات المحتوى؛ هذا العقد لا يضيفها.

**اختبارات مرجعية:** `tests/Feature/Api/ContentPageApiTest.php::test_admin_content_page_responses_expose_the_persistent_id_without_changing_slug_routes`; `tests/Feature/Api/AdminSeoApiTest.php::test_targets_use_fixed_aliases_and_canonical_paths_must_match_entity` و`test_content_page_seo_is_locale_specific_and_independent_from_content_saves`; `tests/Feature/Api/SeoPublicPayloadTest.php::test_content_page_canonical_mapping_is_locale_free_and_reads_do_not_write`.

## 5. Sitemap feed

**`GET /api/v1/seo/sitemap-items` عام؛ منفذ وله اختبارات موجودة.**

المدخلات الوحيدة: `type` مطلوب ومن القيم `product|category|page`; `cursor` اختياري، opaque string ≤128؛ `limit` اختياري من 1 إلى 100، والافتراضي 50. query إضافي، نوع غير مدعوم، cursor malformed أو cursor لنوع آخر يرجع 422.

شكل الصف **كيان واحد مع قائمة locales**، لا صف منفصل لكل لغة. `nextCursor` مكانه حرفيًا `data.nextCursor`، بحالة camelCase:

```json
{
  "data": {
    "items": [
      {
        "id": 101,
        "entityType": "product",
        "slug": "winter-blanket",
        "eligibleLocales": ["ar", "en"],
        "locales": [
          {
            "locale": "ar",
            "path": "/products/winter-blanket",
            "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
          },
          {
            "locale": "en",
            "path": "/products/winter-blanket",
            "lastSignificantModifiedAt": "2026-05-01T12:00:00.000000Z"
          }
        ]
      }
    ],
    "nextCursor": null
  }
}
```

هذا مثال توضيحي غير متحقق منه، مستند إلى `SeoSitemapItemsService::item`. الحقول `id`, `entityType`, `slug`, `eligibleLocales`, `locales[].locale`, `path`, `lastSignificantModifiedAt` فعلية. لا يوجد `availableLocales` أو `indexable` منفصل في كل صف؛ كل locale مدرج هنا مؤهل. الـ`path` canonical نسبي للموقع وخالٍ من بادئة اللغة والدومين، مثل `/about` لصفحة `about-us`. الفرونت يركب `/{locale}` ثم الدومين مرة واحدة.

الـcursor keyset مبني على ID بترتيب صاعد، ونوع الكيان مدمج داخل قيمة cursor المشفرة. الخدمة تفحص حتى 200 كيان في الطلب الواحد حتى إن لم تنتج صفوفًا مؤهلة. لذلك ممكن أن يرجع:

```json
{ "data": { "items": [], "nextCursor": "eyJ2IjoxLCJ0eXBlIjoicHJvZHVjdCIsImlkIjoyMDB9" } }
```

عندها يجب متابعة `data.nextCursor` رغم أن `items` فارغة. نهاية القراءة الصحيحة هي `nextCursor: null`. إذا `SEO_INDEXING_ALLOWED=false` فالاستجابة الحالية فارغة مع cursor null. خطأ القراءة ليس قائمة نجاح فارغة؛ الاستثناء ينتج استجابة HTTP فاشلة (المقصود تغطيته باختبار `test_sitemap_read_failure_is_not_returned_as_an_empty_successful_page`).

الصفحات المسودة/المؤرشفة/غير العامة، صفحات الآباء المخفية، noindex، locale غير المتاح، canonical غير المحدد، ومصدر المسار المتعارض مع redirect لا تدخل النتائج. نفاد مخزون المنتج وحده لا يستبعده. `lastSignificantModifiedAt` هو تاريخ الخدمة الحالية لكل لغة؛ ليس وقت إنشاء الـfeed.

**حدود الاتساق:** cursor يتقدم على ID لا على snapshot. في traversal ثابت لا يعيد الصفوف السابقة؛ لكن التغييرات أثناء المرور قد تجعل سجلًا قديمًا يصبح مؤهلًا بعد تجاوز ID فيُفقد في هذا المرور، أو تجعل أهلية سجل لاحق تتغير قبل قراءته. لا يوجد ضمان snapshot ثابت. لا يوجد endpoint manifest/shard منفصل ولا حد خاص 5,000 بالباك.

**اختبارات مرجعية:** `tests/Feature/Api/SeoSitemapItemsApiTest.php::test_product_items_continue_across_cursor_pages_without_missing_or_repeating_rows`; `test_public_items_continue_past_noindex_rows_and_include_locale_specific_eligibility`; `test_category_and_content_page_paths_use_the_existing_visibility_and_canonical_rules`; `test_invalid_type_cursor_limit_and_unknown_parameters_return_validation_errors`; `test_sitemap_read_failure_is_not_returned_as_an_empty_successful_page`.

## 6. Redirects

### Resolver العام

`GET /api/v1/seo/redirects/resolve?path=...` عام. `path` مطلوب، حتى 2048 حرفًا، ويجب أن يكون مسارًا داخليًا مدعومًا بعد normalization. مدخل URL كامل أو login/admin/checkout أو parameter زائد ينتج 422. لا يوجد redirect HTTP من API.

النتيجة:

```json
{ "data": { "destinationPath": "/ar/products/winter-blanket", "statusCode": 301 } }
```

عدم وجود redirect صالح يعيد 200 و`data: null`، وليس 404. resolver لا يعيد بيانات الإدارة، ويتحقق من ظهور الوجهة لحظة الحل ومن عدم وجود self-loop أو chain. الفرونت يضيف الدومين المعتمد وينفذ 301/308 على دومينه.

تغيير slug المنتج أو القسم العام ينشئ مسارات قديمة للغتين حتى لو كان `noindex` ما دام الكيان متاحًا للعامة. تغيير slug القسم يستخدم `/ar/category/{oldSlug}` و`/en/category/{oldSlug}`، وتُحوّل الوجهتان إلى slug الحالي. عند تكرار التغيير تُسطّح الوجهات التاريخية لمنع chain، وتُرفض إعادة استخدام slug محجوز. القسم غير العام بسبب حالته أو ظهور أحد آبائه لا ينشئ تحويلًا. التحديث والتحويلات وسجل التدقيق داخل transaction واحدة؛ no-op لا ينشئ سجلًا. إشعار revalidation بعد commit يتضمن المسارات القديمة والجديدة عند تفعيله. الباك لا ينفذ HTTP redirect؛ storefront يستهلك resolver وينفذ التحويل على نطاقه.

### الإدارة

المسارات تحت `auth:sanctum` و`admin.access`:

- `GET /api/v1/admin/redirects?page=&per_page=`؛ الافتراضي 20، حد `per_page` 100، وترتيب آخر تعديل ثم ID تنازلي.
- `POST /api/v1/admin/redirects` body: `sourcePath`, `destinationPath`, `statusCode` (301 أو 308)؛ نجاح الإنشاء 201.
- `PATCH /api/v1/admin/redirects/{id}` body جزئي من `destinationPath`, `statusCode`, `isActive`؛ يجب إرسال حقل واحد على الأقل.
- `DELETE /api/v1/admin/redirects/{id}` يعطل السجل ويحفظ التاريخ.

المصادر والوجهات مسارات محلية فقط، والوجهة يجب أن تكون عامة عند الإنشاء/التعديل وعند resolver. المصدر لا يجوز أن يطابق صفحة حية أو يتعارض مع مصدر محجوز. أخطاء validation/تعارض 422؛ guest 401؛ مستخدم غير مخول 403؛ redirect غير موجود 404.

**اختبارات مرجعية:** `tests/Feature/Api/SeoRedirectApiTest.php::test_public_category_slug_changes_create_locale_redirects_flatten_history_and_revalidate_paths`, `test_nonpublic_category_slug_changes_and_public_category_noops_do_not_create_redirects`, `test_category_slug_update_rolls_back_when_old_source_conflicts`، واختبارات المنتج الموجودة لتغيير slug مرتين، no-op، إعادة الاستخدام، والـaudit والصلاحيات. لم تُشغّل الاختبارات في هذا التسليم.

## 7. تقرير التدقيق

`GET /api/v1/admin/seo/audit` تحت حماية الإدارة الحالية، بلا مدخلات. يعيد JSON في `data` ويقرأ فقط.

- `products.indexEligible.distinctProducts` يحسب المنتج مرة واحدة؛ `byLocale` و`totalProductLocalePages` وحدتهما صفحات اللغة.
- `customSeoTitleByLocale` و`missingUsefulDescriptionByLocale` تخص منتجات عامة، والوصف يحسب بعد fallback والتنظيف؛ لا يوجد حد طول اختياري لتعريف «المفيد».
- الصور تظهر تحت `seoImages.invalidCustomOverrides`، أي overrides صريحة فقط. fallback غير مخصص لا يعد خطأ في هذا المؤشر؛ الصور الافتراضية نفسها ليست مضمونة التحقق ضمن المؤشر الحالي.
- `sitemap.totalEligibleUrls` يحصي أهلية Laravel الحالية، لا XML منشورًا ولا URLs مفهرسة لدى Google.
- `xmlPublicationStatus` و`lastGenerationError` يظلان null و`publicationStatusAvailable=false` لعدم وجود مصدر نشر موثوق.
- `activeRedirects` يعد سجلات redirect النشطة عبر نطاقات الدومين.

**اختبارات مرجعية موجودة وغير مشغلة هنا:** `tests/Feature/Api/AdminSeoAuditApiTest.php` تشمل guest/صلاحيات، empty catalog، العدادات، fallback، snapshots لعدم الكتابة أثناء GET. هذه العدادات ليست بديلًا لـGoogle Search Console.

## 8. إشعارات إعادة التحقق: تكامل منفصل

Laravel يكوّن حدث revalidation بعد تغييرات المحتوى عبر transaction callbacks، ويضع `SendSeoRevalidation` على queue غير متزامنة. الـjob يوقع بايتات JSON الخام بـHMAC SHA-256، ويثبت body/eventId في retry ويجدد timestamp/signature، ويحاول 5 مرات مع backoff `[10, 60, 300, 900]`، ويحترم `Retry-After` حيث ينطبق. الرد المقبول 200 مع JSON يطابق `version`, `eventId`, و`status: accepted`. أحداث بدء/انتهاء العرض والنشر المجدول يمر عليها scheduler كل دقيقة باستخدام checkpoint دائم.

الإعداد الافتراضي في config هو `SEO_REVALIDATION_ENABLED=false`. هذا لا يثبت القيمة الفعلية للبيئة؛ لم أقرأ أسرار `.env` ولم أتحقق من تشغيل worker أو scheduler. فشل enqueue يسجل الخطأ ولا يُحوّل نجاح عملية المتجر إلى فشل. العقد لا يضمن exactly-once ولا يغطي انقطاعًا بين commit وenqueue عبر outbox.

اختبارات المصدر تشمل:

- `tests/Feature/Services/SendSeoRevalidationTest.php::test_job_signs_the_exact_contract_body_and_accepts_matching_acknowledgement`
- `test_retry_keeps_body_and_event_id_but_signs_with_a_new_timestamp`
- `test_five_attempt_retry_policy_uses_the_contract_delays`
- `test_rate_limit_retry_after_is_respected_without_retrying_inside_the_request`
- `tests/Feature/Services/SeoRevalidationQueueTest.php::test_notification_is_queued_only_after_commit_and_discarded_on_rollback`
- `tests/Feature/Services/SeoTimeBoundaryRevalidationTest.php::test_downtime_boundaries_are_enqueued_before_the_checkpoint_advances`

هذه تغطية برمجية بـHTTP fake/queue حسب source tests؛ لم أشغّلها الآن، ولا تثبت أن worker/scheduler يعملان أو أن Next receiver يقبل التوقيع. الاختبار المشترك لـUTF-8 يفحص الجسم المرسل الفعلي، لكن لا أورد نتيجة تنفيذ حديثة أو أعتبر receiver منشورًا. لا ترسل أسرارًا أو HMAC production في هذا الملف.

### المسؤوليات حتى قبول التكامل

1. الفرونت/Next: تنفيذ receiver، التحقق من raw-body HMAC وtimestamp، idempotency، scope-to-cache/route map، ومعالجة نتائج invalidation ثم الرد بالعقد المحدد.
2. الفرونت: ربط scopes بالـtags والمسارات التي تُخزن فعلًا؛ معظم catalog fetches مذكورة في الطلب كـ`no-store`، ومحتوى الصفحات وSitemap وMeta feed لها سياسات مختلفة. لا يمكن تأكيد التغطية أو CDN `s-maxage` من Laravel وحده.
3. التشغيل: ضبط URL/secret بأمان، queue persistent غير `sync`/`null`، worker دائم، scheduler فعلي كل دقيقة، وقت UTC متزامن، ومراقبة failed jobs/enqueue/checkpoint.
4. الطرفان: تشغيل اختبار قبول على backend قابل للوصول من نفس النسخة، ثم مقارنة fixture حقيقي آمن واختبار refresh على البيانات المخبأة فعليًا. staging يبقى غير مفهرس.

## 9. نتائج التحقق لهذا التسليم

- لم تُشغّل اختبارات أو HTTP أو migrations في هذا التسليم.
- PHP غير متاح في PATH الحالي؛ لذلك لا أورد نجاحًا جديدًا لاختبار PHP أو HMAC.
- أرقام الاختبارات التي أُبلغت سابقًا في المحادثة هي تقارير المستخدم لنسخ/مراحل سابقة، وليست نتائج لهذه الشجرة الحالية.
- لم أتحقق من base URL أو deployment أو worker أو scheduler أو PostgreSQL فعلي. طلب الفرونت نفسه يوضح أن ملاحظاته عن 503/404 كانت عن نشر سابق.

## 10. النواقص المؤكدة وأصغر إجراء

| النقص المؤكد | أصغر إجراء | المسؤول |
|---|---|---|
| لم يُحسم منطق الصفحة الرئيسية/المتجر/التواصل خارج هذا endpoint (مثل محتوى الصفحة أو فلاتر الكتالوج) | الفرونت يركب القيم المحلولة للمسارات الثابتة؛ أي بيانات إضافية تحتاج عقدًا وقرارًا منفصلين | الطرفان |
| `ContentPageResource` لا يعيد `structuredData`، وmissing locale لا ينتج 404 تلقائيًا | تحديد هل مطلوب structured data للمحتوى؛ لا تغيّر سياسة الترجمة قبل قرار؛ استهلك `seo.availableLocales/indexable` الحالية | الطرفان؛ قرار سلوك missing locale مطلوب |
| فلاتر/ترتيبات catalog المطلوبة غير منفذة | تحديد query contract للسعر الفعلي والعروض/الألوان/المجموعة/الترتيب ثم تنفيذها واختبارها قبل إزالة filtering بعد pagination في الفرونت | الطرفان، التنفيذ الباقي بالباك |
| Sitemap يعيد entity rows لا locale rows، ولا يضمن snapshot | الفرونت يستهلك `data.items[].locales[]` ويتابع `data.nextCursor` حتى null، حتى مع items فارغة؛ لا ينشر ملفًا جزئيًا عند خطأ دفعة | الفرونت |
| لا يوجد mapping موثوق لـprivacy-policy، ولا terms في الصفحات المضبوطة | اعتماد مسارات الزوار أولًا؛ لا تخمين | مالك مسارات الفرونت/المنتج |
| مؤشر التدقيق يقتصر على custom image overrides | إذا كان المقصود كل صورة SEO نهائية، قيّم الصورة المحلولة بعد fallback وعرّف العد بوضوح | الباك |
| لا توجد حالة نشر XML أو آخر خطأ توليد موثوقة | لا تعرض قيمًا رقمية موحية؛ إضافة مصدر نشر مستقل فقط إذا تقرر أن المسؤولية تقع في الباك | مسؤول Sitemap؛ غالبًا الفرونت/التشغيل |
| worker/scheduler وNext receiver/CDN لم يتم قبولهم تكامليًا | اختبار end-to-end آمن بعد تجهيز الطرفين والتشغيل؛ إبقاء `SEO_REVALIDATION_ENABLED=false` حتى ذلك الوقت | الفرونت + التشغيل + الباك |
