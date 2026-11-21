@php
    $signupScreens = ['name' => 'نام شما', 'address' => 'نشانی کیوسک', 'security' => 'امنیت حساب'];
    $step = array_search($screen, array_keys($signupScreens), true);
    $domain = config('auth.mailbox_domain');
    $title = match ($screen) {
        'name' => 'شما را چه صدا کنیم؟',
        'address' => 'نشانی‌تان را خودتان انتخاب کنید.',
        'security' => 'کلید صندوق دست شماست.',
        'verify' => 'ایمیل بازیابی را تأیید کنید.',
        'login' => 'به کیوسک خوش آمدید.',
        'forgot' => 'گذرواژه را فراموش کرده‌اید؟',
        'reset' => 'یک گذرواژهٔ تازه بسازید.',
    };
@endphp
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#fdfcfb">
    <meta name="robots" content="noindex">
    <title>{{ $title }} | کیوسک</title>
    @vite('resources/css/app.css')
</head>
<body class="account-page">
    <header class="account-header">
        <a class="brand" href="{{ route('landing') }}">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg></span>
            کیوسک
        </a>
        @guest
            @if ($screen === 'login')
                <a class="secondary" href="{{ route('register') }}">ساخت حساب</a>
            @else
                <a class="secondary" href="{{ route('login') }}">ورود به حساب</a>
            @endif
        @else
            <form method="post" action="{{ route('logout') }}">@csrf<button class="secondary">خروج</button></form>
        @endguest
    </header>
    <main class="account-card" aria-labelledby="account-heading">
        @if ($step !== false)
            <ol class="account-progress" aria-label="مراحل ثبت‌نام">
                @foreach ($signupScreens as $label)
                    <li @if ($loop->index === $step) aria-current="step" @endif @class(['completed' => $loop->index < $step])>{{ $label }}</li>
                @endforeach
            </ol>
        @endif
        <h1 id="account-heading">{{ $title }}</h1>

        @if (session('status'))
            <p class="account-status" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <ul class="account-errors" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @switch($screen)
            @case('name')
                <p>این نام را کسانی که از شما نامه می‌گیرند می‌بینند.</p>
                <form method="post" action="{{ route('register.store') }}" class="account-form">
                    @csrf
                    <label for="signup-name">نام و نام خانوادگی</label>
                    <input id="signup-name" name="name" type="text" autocomplete="name" dir="auto" value="{{ old('name', $signup['name'] ?? '') }}" maxlength="100" required @error('name') aria-invalid="true" @enderror>
                    <button class="primary">ادامه</button>
                </form>
                @break

            @case('address')
                <p>این همان نشانی‌ای است که به دیگران می‌دهید.</p>
                <form method="post" action="{{ route('register.store', ['step' => 'address']) }}" class="account-form">
                    @csrf
                    <label for="signup-handle">نام کاربری</label>
                    <div class="address-field" dir="ltr">
                        <input id="signup-handle" name="handle" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" value="{{ old('handle', $signup['handle'] ?? '') }}" minlength="3" maxlength="32" aria-describedby="address-hint" required @error('handle') aria-invalid="true" @enderror>
                        <bdi>{{ '@'.$domain }}</bdi>
                    </div>
                    <small id="address-hint">۳ تا ۳۲ حرف یا عدد انگلیسی. نقطه، خط تیره و زیرخط فقط بین آن‌ها مجازند.</small>
                    <div class="account-actions"><button class="primary">ادامه با این نشانی</button><a href="{{ route('register') }}">بازگشت</a></div>
                </form>
                @break

            @case('security')
                <p>نشانی تازهٔ شما <bdi dir="ltr" class="account-address">{{ $signup['handle'].'@'.$domain }}</bdi> است.</p>
                <form method="post" action="{{ route('register.store', ['step' => 'security']) }}" class="account-form">
                    @csrf
                    <label for="recovery-email">ایمیل بازیابی</label>
                    <input id="recovery-email" name="email" type="email" dir="ltr" autocomplete="email" value="{{ old('email') }}" aria-describedby="recovery-hint" required @error('email') aria-invalid="true" @enderror>
                    <small id="recovery-hint">نشانی‌ای که همین حالا به آن دسترسی دارید. لینک تأیید را به آن می‌فرستیم.</small>
                    @include('auth.password-fields')
                    <div class="account-actions"><button class="primary">ساخت حساب</button><a href="{{ route('register', ['step' => 'address']) }}">تغییر نشانی</a></div>
                </form>
                @break

            @case('verify')
                @if (auth()->user()->hasVerifiedEmail())
                    <p>نشانی بازیابی شما تأیید شده است.</p>
                    <a class="primary account-standalone" href="{{ route('imbox') }}">باز کردن صندوق</a>
                @else
                    <p>ایمیل کیوسک را در <bdi dir="ltr" class="account-address">{{ auth()->user()->email }}</bdi> باز کنید و روی لینک تأیید بزنید.</p>
                    <form method="post" action="{{ route('verification.send') }}" class="account-form">
                        @csrf
                        <button class="primary">ارسال دوبارهٔ لینک</button>
                    </form>
                @endif
                @break

            @case('login')
                <p>با نشانی کیوسک یا ایمیل بازیابی وارد شوید.</p>
                <form method="post" action="{{ route('login.store') }}" class="account-form">
                    @csrf
                    <label for="login-email">نشانی ایمیل</label>
                    <input id="login-email" name="email" type="email" dir="ltr" autocomplete="username" value="{{ old('email') }}" required @error('email') aria-invalid="true" @enderror>
                    <label for="login-password">گذرواژه</label>
                    <input id="login-password" name="password" type="password" dir="ltr" autocomplete="current-password" required @error('password') aria-invalid="true" @enderror>
                    <div class="account-actions"><button class="primary">ورود</button><a href="{{ route('password.request') }}">بازیابی گذرواژه</a></div>
                </form>
                @break

            @case('forgot')
                <p>ایمیل بازیابی حساب را وارد کنید تا لینک تغییر گذرواژه را دریافت کنید.</p>
                <form method="post" action="{{ route('password.email') }}" class="account-form">
                    @csrf
                    <label for="forgot-email">ایمیل بازیابی</label>
                    <input id="forgot-email" name="email" type="email" dir="ltr" autocomplete="email" value="{{ old('email') }}" required @error('email') aria-invalid="true" @enderror>
                    <div class="account-actions"><button class="primary">ارسال لینک</button><a href="{{ route('login') }}">بازگشت به ورود</a></div>
                </form>
                @break

            @case('reset')
                <form method="post" action="{{ route('password.update') }}" class="account-form">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <label for="reset-email">ایمیل بازیابی</label>
                    <input id="reset-email" name="email" type="email" dir="ltr" autocomplete="email" value="{{ old('email', $resetEmail) }}" required @error('email') aria-invalid="true" @enderror>
                    @include('auth.password-fields')
                    <div class="account-actions"><button class="primary">ذخیرهٔ گذرواژه</button><a href="{{ route('password.request') }}">دریافت لینک تازه</a></div>
                </form>
                @break
        @endswitch
    </main>
</body>
</html>
