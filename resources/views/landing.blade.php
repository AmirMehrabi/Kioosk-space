<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="کیوسک، صندوق نامهٔ فارسی برای خواندن، مرتب کردن و پاسخ دادن به ایمیل‌ها.">
    <meta name="theme-color" content="#fdfcfb">
    <meta property="og:type" content="website">
    <meta property="og:title" content="کیوسک | نامه‌های مهم، دم دست">
    <meta property="og:description" content="کیوسک، صندوق نامهٔ فارسی برای خواندن، مرتب کردن و پاسخ دادن به ایمیل‌ها.">
    <meta property="og:url" content="{{ route('landing') }}">
    <meta property="og:image" content="{{ asset('kioosk-logos/kioosk-logo-bilingual-blue-badge.png') }}">
    <meta property="og:image:width" content="2172">
    <meta property="og:image:height" content="724">
    <meta property="og:image:alt" content="کیوسک | Kioosk">
    <title>کیوسک | نامه‌های مهم، دم دست</title>
    <x-kioosk-icons />
    @vite(['resources/css/app.css', 'resources/css/landing.css', 'resources/js/app.js'])
</head>
<body class="landing">
    <a class="skip-link" href="#landing-content">رفتن به محتوای اصلی</a>
    <header class="landing-header">
        <a class="landing-brand" href="{{ route('landing', absolute: false) }}">
            <img class="landing-logo" src="{{ asset('kioosk-logos/kioosk-logo-horizontal-persian.png') }}"
                width="1050" height="356" alt="کیوسک">
        </a>
        <nav aria-label="صفحهٔ اصلی">
            <a class="landing-nav-link" href="#folders">صندوق چطور کار می‌کند؟</a>
            @auth
                <a class="landing-button landing-button-quiet" href="{{ route('imbox', absolute: false) }}">باز کردن صندوق</a>
            @else
                <a class="landing-button landing-button-quiet" href="{{ route('login', absolute: false) }}">ورود</a>
            @endauth
        </nav>
    </header>

    <main id="landing-content" tabindex="-1">
        <section class="landing-hero landing-wrap" x-data aria-labelledby="landing-title">
            <div class="landing-hero-grid">
                <div class="landing-intro">
                    <h1 id="landing-title">همهٔ نامه‌ها <em>فوری نیستند.</em></h1>
                    <p>کیوسک یک صندوق نامهٔ فارسی است. نامه‌های مهم را همین حالا ببینید، خبرنامه‌ها را سر فرصت بخوانید و رسیدها را وقتی لازم شد پیدا کنید.</p>
                    <div class="hero-actions">
                        @auth
                            <a class="landing-button" href="{{ route('imbox', absolute: false) }}">باز کردن صندوق</a>
                        @else
                            <a class="landing-button" href="{{ route('register', absolute: false) }}">نشانی خود را بسازید</a>
                        @endauth
                        <button type="button" class="landing-button landing-button-quiet" aria-haspopup="dialog"
                            x-cloak @click="$refs.preview.showModal()">پیش‌نمایش</button>
                    </div>
                    <small>نشانی شخصی شما در <bdi>@kioosk.space</bdi></small>
                </div>
                <div class="hero-stage">
                    <div class="landing-preview" aria-hidden="true">
                        <div class="preview-header">
                            <span>صندوق نامه‌ها</span>
                            <img class="preview-wordmark" src="{{ asset('kioosk-logos/kioosk-wordmark-english.png') }}" width="2172" height="724" alt="">
                            <span class="preview-user">آ</span>
                        </div>
                        <div class="preview-body">
                            <div class="preview-title">نامه‌های مهم <span>۳ تازه</span></div>
                            <div class="preview-row"><i>س</i><div><strong>نسخهٔ نهایی طرح‌ها آماده شد</strong><small>سحر مرادی</small></div><time>۱۰:۴۲</time></div>
                            <div class="preview-row"><i>م</i><div><strong>هماهنگی جلسهٔ دوشنبه</strong><small>مهرداد فلاح</small></div><time>۰۹:۱۸</time></div>
                            <div class="preview-row"><i>ر</i><div><strong>عکس‌های سفر یزد</strong><small>رویا نادری</small></div><time>دیروز</time></div>
                        </div>
                    </div>
                </div>
            </div>

            <dialog class="landing-dialog" x-ref="preview" aria-labelledby="preview-heading">
                <div class="dialog-header">
                    <h2 id="preview-heading">پیش‌نمایش صندوق کیوسک</h2>
                    <form method="dialog"><button type="submit" class="landing-button landing-button-quiet" autofocus>بستن</button></form>
                </div>
                <p>نمایی از پوشه‌های صندوق کیوسک. برای باز کردن صندوق خود، وارد حساب شوید.</p>
                <div class="dialog-folders">
                    <a href="{{ route('imbox', absolute: false) }}"><strong>نامه‌های مهم</strong><span>نسخهٔ نهایی طرح‌ها آماده شد</span></a>
                    <a href="{{ route('imbox', absolute: false) }}#feed"><strong>خواندنی‌ها</strong><span>سه خواندنی برای آبان</span></a>
                    <a href="{{ route('imbox', absolute: false) }}#receipts"><strong>رسیدها</strong><span>تأیید سفارش کتاب</span></a>
                </div>
            </dialog>
        </section>

        <nav class="landing-jump landing-wrap" id="folders" aria-label="رفتن به بخش‌های صندوق">
            <a href="#important">نامه‌های مهم</a>
            <a href="#receipts">رسیدها</a>
            <a href="#feed">خواندنی‌ها</a>
            <a href="#later">پاسخ بعداً</a>
        </nav>

        <section class="landing-feature feature-important" id="important" aria-labelledby="important-heading">
            <div class="landing-wrap">
                <h2 id="important-heading">اول، نامه‌هایی که <em>مهم‌اند.</em></h2>
                <p>تازه‌ها بالای صندوق می‌مانند. همین که نامه‌ای را باز کنید، به «دیده‌شده‌ها» می‌رود و جایش برای نامهٔ بعدی باز می‌شود.</p>
                <div class="landing-preview" aria-hidden="true">
                    <div class="preview-header"><span>نامه‌های مهم</span><strong>کیوسک</strong><span class="preview-user">آ</span></div>
                    <div class="preview-body">
                        <h3 class="preview-group">تازه برای شما</h3>
                        <div class="preview-row"><i>م</i><div><strong>هماهنگی جلسهٔ دوشنبه</strong><small>مهرداد فلاح</small></div><time>۰۹:۱۸</time></div>
                        <div class="preview-row"><i>ر</i><div><strong>عکس‌های سفر یزد</strong><small>رویا نادری</small></div><time>دیروز</time></div>
                        <h3 class="preview-group">دیده‌شده‌ها</h3>
                        <div class="preview-row"><i>س</i><div><strong>نسخهٔ نهایی طرح‌ها آماده شد</strong><small>سحر مرادی</small></div><time>۱۰:۴۲</time></div>
                    </div>
                </div>
                <a class="feature-link" href="{{ route('imbox', absolute: false) }}">باز کردن نامه‌های مهم</a>
            </div>
        </section>

        <section class="landing-feature feature-receipts" id="receipts" aria-labelledby="receipts-heading">
            <div class="landing-wrap">
                <h2 id="receipts-heading">رسید را همان موقع می‌خواهید که <em>لازمش دارید.</em></h2>
                <p>تأیید خرید و رزرو در پوشهٔ رسیدها کنار هم هستند. برای پیدا کردن شمارهٔ سفارش، از میان نامه‌های روزمره نگردید.</p>
                <div class="landing-preview" aria-hidden="true">
                    <div class="preview-header"><span>رسیدها</span><strong>کیوسک</strong><span class="preview-user">آ</span></div>
                    <div class="preview-body">
                        <div class="preview-row"><i>ف</i><div><strong>تأیید سفارش کتاب</strong><small>فروشگاه نمونه · سفارش ۲۴۸۱</small></div><time>۲۶ آبان</time></div>
                        <div class="preview-row"><i>س</i><div><strong>تأیید رزرو سفر</strong><small>سفر نمونه · پیگیری ۸۲۱۰</small></div><time>۲۵ آبان</time></div>
                    </div>
                </div>
                <a class="feature-link" href="{{ route('imbox', absolute: false) }}#receipts">باز کردن رسیدها</a>
            </div>
        </section>

        <section class="landing-feature feature-feed" id="feed" aria-labelledby="feed-heading">
            <div class="landing-wrap">
                <h2 id="feed-heading">خبرنامه را <em>سرِ فرصت</em> بخوانید.</h2>
                <p>خواندنی‌ها جای نوشته‌هایی است که خودتان دوست دارید دنبال کنید. هر وقت فرصت شد، یکی را باز کنید و بخوانید.</p>
                <div class="landing-preview" aria-hidden="true">
                    <div class="preview-header"><span>خواندنی‌ها</span><strong>کیوسک</strong><span class="preview-user">آ</span></div>
                    <div class="preview-body preview-article">
                        <small>دفتر مطالعه · دیروز</small>
                        <h3>سه خواندنی برای آبان</h3>
                        <p>در این شماره دربارهٔ طراحی برای خواندن، عادت‌های یادداشت‌برداری و چند پادکست فارسی می‌خوانیم.</p>
                    </div>
                </div>
                <a class="feature-link" href="{{ route('imbox', absolute: false) }}#feed">باز کردن خواندنی‌ها</a>
            </div>
        </section>

        <section class="landing-feature feature-later" id="later" aria-labelledby="later-heading">
            <div class="landing-wrap">
                <h2 id="later-heading">خواندنش الان. <em>جوابش بعداً.</em></h2>
                <p>اگر برای جواب دادن وقت بیشتری می‌خواهید، نامه را به «پاسخ بعداً» بسپارید. دفعهٔ بعد مستقیم سراغ همان نامه بروید.</p>
                <div class="landing-preview" aria-hidden="true">
                    <div class="preview-header"><span>پاسخ بعداً</span><strong>کیوسک</strong><span class="preview-user">آ</span></div>
                    <div class="preview-body">
                        <div class="preview-row"><i>س</i><div><strong>یادداشت‌های جلسهٔ طراحی</strong><small>استودیو سپید</small></div><time>دیروز</time></div>
                    </div>
                </div>
                <a class="feature-link" href="{{ route('imbox', absolute: false) }}#later">باز کردن پاسخ بعداً</a>
            </div>
        </section>

        <section class="landing-end">
            <div class="landing-wrap">
                <h2>یک نامه را باز کنید.</h2>
                <p>نشانی خودتان را بسازید و اولین نامه را از صندوق کیوسک بفرستید.</p>
                @auth
                    <a class="landing-button" href="{{ route('imbox', absolute: false) }}">ورود به صندوق</a>
                @else
                    <a class="landing-button" href="{{ route('register', absolute: false) }}">ساخت حساب کیوسک</a>
                @endauth
            </div>
        </section>
    </main>

    <footer class="landing-footer landing-wrap">
        <a class="landing-footer-brand" href="{{ route('landing', absolute: false) }}">
            <img src="{{ asset('kioosk-logos/kioosk-logo-stacked-bilingual.png') }}"
                width="1254" height="1254" alt="کیوسک | Kioosk" loading="lazy">
        </a>
        <a href="{{ route('imbox', absolute: false) }}">صندوق نامه‌ها</a>
    </footer>
</body>
</html>
