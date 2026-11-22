<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

Route::middleware('guest')->group(function (): void {
    Route::get('/register/{step?}', [RegistrationController::class, 'show'])
        ->whereIn('step', ['name', 'address', 'security'])->name('register');
    Route::post('/register/{step?}', [RegistrationController::class, 'store'])
        ->whereIn('step', ['name', 'address', 'security'])->middleware('throttle:12,1,register')->name('register.store');

    Route::view('/login', 'auth.account', ['screen' => 'login'])->name('login');
    Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:8,1,login')->name('login.store');
    Route::view('/forgot-password', 'auth.account', ['screen' => 'forgot'])->name('password.request');
    Route::post('/forgot-password', [AccountController::class, 'sendResetLink'])->middleware('throttle:5,1,password-link')->name('password.email');
    Route::get('/reset-password/{token}', [AccountController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:8,1,password-reset')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::view('/verify-email', 'auth.account', ['screen' => 'verify'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [AccountController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1,verify'])->name('verification.verify');
    Route::post('/email/verification-notification', [AccountController::class, 'resendVerification'])
        ->middleware('throttle:3,1,verify-resend')->name('verification.send');
    Route::post('/logout', [AccountController::class, 'logout'])->name('logout');

    Route::middleware('verified')->group(function (): void {
        Route::view('/mail', 'mail')->name('imbox');
        Route::get('/mail/data', [MailboxController::class, 'index'])->name('mail.data');
        Route::get('/mail/message/{id}', [MailboxController::class, 'show']);
        Route::get('/mail/message/{id}/attachment/{blob}', [MailboxController::class, 'attachment'])->name('mail.attachment');
        Route::patch('/mail/message/{id}', [MailboxController::class, 'flag']);
        Route::post('/mail/message/{id}/organize', [MailboxController::class, 'organize']);
        Route::post('/mail/draft/open', [MailboxController::class, 'openDraft']);
        Route::put('/mail/draft/{id}', [MailboxController::class, 'draft'])->whereUuid('id');
        Route::delete('/mail/draft/{id}', [MailboxController::class, 'discardDraft'])->whereUuid('id');
        Route::post('/mail/draft/{id}/attachments', [MailboxController::class, 'upload'])
            ->whereUuid('id')->middleware('throttle:25,1,mail-upload');
        Route::post('/mail/send', [MailboxController::class, 'send'])->middleware('throttle:10,1,mail-send');
        Route::view('/mail/compose', 'mail', ['composing' => true])->name('compose');
        Route::view('/compose', 'mail', ['composing' => true]);
    });
});
