<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AccountController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:256'],
        ], ['email' => 'یک نشانی ایمیل معتبر وارد کنید.', 'password.required' => 'گذرواژه را وارد کنید.']);

        $email = strtolower($data['email']);
        $column = str_ends_with($email, '@'.config('auth.mailbox_domain')) ? 'mailbox_address' : 'email';

        if (strlen($data['password']) > 72 || ! Auth::attempt([$column => $email, 'password' => $data['password']])) {
            return back()->withInput(['email' => $email])->withErrors(['email' => 'نشانی یا گذرواژه درست نیست.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('imbox'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('landing');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('imbox'));
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return to_route('imbox');
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return back()->withErrors(['delivery' => 'ارسال ایمیل تأیید انجام نشد. دوباره تلاش کنید.']);
        }

        return back()->with('status', 'لینک تازه به نشانی بازیابی شما فرستاده شد.');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']], [
            'email' => 'یک نشانی ایمیل بازیابی معتبر وارد کنید.',
        ]);

        try {
            Password::sendResetLink(['email' => strtolower($data['email'])]);
        } catch (TransportExceptionInterface $exception) {
            report($exception);
        }

        return back()->with('status', 'اگر حسابی با این نشانی باشد، لینک بازیابی را دریافت می‌کنید.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.account', ['screen' => 'reset', 'token' => $token, 'resetEmail' => $request->query('email')]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ], [
            'email' => 'نشانی بازیابی معتبر وارد کنید.',
            'password.required' => 'یک گذرواژه انتخاب کنید.',
            'password.confirmed' => 'تکرار گذرواژه یکسان نیست.',
            'password.min' => 'گذرواژه باید دست‌کم ۱۲ نویسه داشته باشد.',
        ]);

        $data['email'] = strtolower($data['email']);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PasswordReset) {
            return back()->withInput(['email' => $data['email']])->withErrors(['email' => 'لینک بازیابی نامعتبر یا منقضی شده است. یک لینک تازه درخواست کنید.']);
        }

        return to_route('login')->with('status', 'گذرواژه تغییر کرد. با گذرواژهٔ تازه وارد شوید.');
    }
}
