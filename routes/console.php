<?php

use App\Models\User;
use App\Services\ScreeningSetup;
use App\Services\SenderScreening;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mail:screener-enable {mailbox}', function (ScreeningSetup $setup) {
    $user = User::query()->where('mailbox_address', $this->argument('mailbox'))->first();
    if (! $user || ! $user->hasVerifiedEmail()) {
        $this->error('A verified Kioosk mailbox is required.');

        return 1;
    }

    $setup->enable($user);
    $this->info('Screening enabled for '.$user->mailbox_address);

    return 0;
})->purpose('Enable delivery-time screening for a verified mailbox');

Artisan::command('mail:screener-sync', function (SenderScreening $screening) {
    $failed = false;
    foreach (User::query()->where('screening_enabled', true)->lazyById() as $user) {
        try {
            $screening->sync($user);
        } catch (RuntimeException $exception) {
            report($exception);
            $failed = true;
        }
    }

    return $failed ? 1 : 0;
})->purpose('Release approved inbound mail and retry pending screening decisions')->everyMinute()->withoutOverlapping(10);
