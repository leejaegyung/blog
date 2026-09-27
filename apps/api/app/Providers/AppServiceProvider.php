<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // 스케줄러처럼 요청 없이 시작한 작업에도 trace_id를 붙인다. 요청에서 온 작업은 Context로 이어받는다.
        Queue::before(function (JobProcessing $event) {
            if (! Context::has('trace_id')) {
                Context::add('trace_id', (string) Str::uuid());
            }
            Context::add('job', $event->job->resolveName());
        });
        // 콘솔 명령(스케줄러 포함)도 로그를 한 실행 단위로 묶는다
        Event::listen(CommandStarting::class, function () {
            if (! Context::has('trace_id')) {
                Context::add('trace_id', (string) Str::uuid());
            }
        });
        Queue::failing(function (JobFailed $event) {
            Log::error('job_failed', [
                'job' => $event->job->resolveName(),
                'error' => $event->exception::class.': '.mb_strimwidth($event->exception->getMessage(), 0, 200, '…'),
            ]);
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('login')).'|'.$request->ip());
        });
    }
}
