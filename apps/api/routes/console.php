<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 운영 작업 (scheduler 컨테이너가 실행)
Schedule::command('app:recover-stuck')->everyTenMinutes()->withoutOverlapping();
// 매시간 백업(최근 72개 = 3일). 실수로 지운 데이터를 한 시간 안쪽으로 되살릴 수 있게
Schedule::command('app:backup')->hourly()->withoutOverlapping();
