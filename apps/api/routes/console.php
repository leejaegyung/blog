<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 운영 작업 (scheduler 컨테이너가 실행)
Schedule::command('app:recover-stuck')->everyTenMinutes()->withoutOverlapping();
Schedule::command('app:backup')->dailyAt('04:00')->timezone('Asia/Seoul')->withoutOverlapping();
