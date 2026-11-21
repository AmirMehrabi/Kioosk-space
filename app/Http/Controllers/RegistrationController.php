<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class RegistrationController extends Controller
{
    public function show(Request $request, string $step = 'name'): View|RedirectResponse
    {
        if ($redirect = $this->missingStep($request, $step)) {
            return $redirect;
        }

        return view('auth.account', ['screen' => $step, 'signup' => $request->session()->get('signup', [])]);
    }

    public function store(Request $request, string $step = 'name'): RedirectResponse
    {
        if ($redirect = $this->missingStep($request, $step)) {
            return $redirect;
        }

        return match ($step) {
            'name' => $this->saveName($request),
            'address' => $this->saveAddress($request),
            'security' => $this->register($request),
        };
    }

    private function missingStep(Request $request, string $step): ?RedirectResponse
    {
        if ($step !== 'name' && ! $request->session()->has('signup.name')) {
            return to_route('register');
        }

        if ($step === 'security' && ! $request->session()->has('signup.handle')) {
            return to_route('register', ['step' => 'address']);
        }

        return null;
    }

    private function saveName(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']], [
            'name.required' => 'نام خودتان را بنویسید.',
            'name.max' => 'نام باید حداکثر ۱۰۰ نویسه باشد.',
        ]);

        $request->session()->put('signup.name', $data['name']);

        return to_route('register', ['step' => 'address']);
    }

    private function saveAddress(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'handle' => ['bail', 'required', 'string', 'min:3', 'max:32', 'regex:/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/i'],
        ], ['handle' => '۳ تا ۳۲ حرف یا عدد انگلیسی وارد کنید. نقطه، خط تیره و زیرخط فقط بین آن‌ها مجازند.']);

        $handle = strtolower($data['handle']);
        $address = $handle.'@'.config('auth.mailbox_domain');

        if (in_array($handle, ['abuse', 'admin', 'notifications', 'billing', 'help', 'hostmaster', 'mailer-daemon', 'no-reply', 'noreply', 'postmaster', 'root', 'security', 'support', 'webmaster'], true)
            || in_array($handle, config('stalwart.reserved'), true)
            || User::where('mailbox_address', $address)->exists()) {
            return back()->withInput()->withErrors(['handle' => 'این نشانی در دسترس نیست. نشانی دیگری انتخاب کنید.']);
        }

        $request->session()->put('signup.handle', $handle);

        return to_route('register', ['step' => 'security']);
    }

    private function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [
            'email' => 'یک نشانی ایمیل بازیابی معتبر وارد کنید.',
            'password.required' => 'یک گذرواژه انتخاب کنید.',
            'password.confirmed' => 'تکرار گذرواژه یکسان نیست.',
            'password.min' => 'گذرواژه باید دست‌کم ۱۲ نویسه داشته باشد.',
        ]);

        $email = strtolower($data['email']);
        $domain = config('auth.mailbox_domain');

        if (str_ends_with($email, '@'.$domain)) {
            return back()->withInput(['email' => $email])->withErrors(['email' => 'برای بازیابی از یک نشانی خارج از کیوسک استفاده کنید.']);
        }

        $address = $request->session()->get('signup.handle').'@'.$domain;

        try {
            $user = User::create([
                'name' => $request->session()->get('signup.name'),
                'mailbox_address' => $address,
                'email' => $email,
                'password' => $data['password'],
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if (User::where('mailbox_address', $address)->exists()) {
                $request->session()->forget('signup.handle');

                return to_route('register', ['step' => 'address'])->withErrors(['handle' => 'این نشانی به‌تازگی انتخاب شده است. نشانی دیگری وارد کنید.']);
            }

            if (User::where('email', $email)->exists()) {
                return back()->withInput(['email' => $email])->withErrors(['email' => 'از نشانی دیگری استفاده کنید یا از صفحهٔ ورود، گذرواژه را بازیابی کنید.']);
            }

            throw $exception;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('signup');

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return to_route('verification.notice')->withErrors(['delivery' => 'ارسال ایمیل تأیید انجام نشد. دوباره تلاش کنید.']);
        }

        return to_route('verification.notice')->with('status', 'لینک تأیید به نشانی بازیابی شما فرستاده شد.');
    }
}
