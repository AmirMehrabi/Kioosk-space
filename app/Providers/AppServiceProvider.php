<?php

namespace App\Providers;

use App\Models\User;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(Password::min(12)->rules([
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('گذرواژه طولانی است. چند نویسه کمتر وارد کنید.');
                }
            },
        ]));

        VerifyEmail::toMailUsing(fn (User $user, string $url) => $this->accountMail(
            'تأیید ایمیل بازیابی کیوسک',
            'برای فعال شدن صندوق کیوسک، نشانی بازیابی خود را تأیید کنید.',
            'تأیید نشانی',
            $url,
        ));

        ResetPassword::toMailUsing(fn (User $user, string $token) => $this->accountMail(
            'بازیابی گذرواژهٔ کیوسک',
            'برای حساب کیوسک شما درخواست تغییر گذرواژه ثبت شده است.',
            'انتخاب گذرواژهٔ تازه',
            route('password.reset', ['token' => $token, 'email' => $user->email]),
        ));
    }

    private function accountMail(string $subject, string $intro, string $action, string $url): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->greeting('سلام!')
            ->line($intro)
            ->action($action, $url)
            ->line('اگر شما این درخواست را ثبت نکرده‌اید، این ایمیل را نادیده بگیرید.')
            ->salutation('کیوسک');
    }
}
