<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 실행 중인 SQLite를 안전하게 복사한다(VACUUM INTO: 트랜잭션 일관성이 보장된 새 파일).
 * 파일을 그대로 cp 하면 WAL 내용이 빠질 수 있다(기획서 24.1 백업).
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:backup {--keep=72 : 남길 백업 개수(같은 종류끼리)} {--tag= : 종류 이름(예: before-migrate). 비우면 정기 백업}';

    protected $description = 'SQLite DB를 /data/backups에 백업하고 오래된 백업을 지운다';

    public function handle(): int
    {
        $dir = config('app.backup_path');
        File::ensureDirectoryExists($dir);
        $tag = preg_replace('/[^a-z0-9-]/', '', (string) $this->option('tag'));
        $prefix = $dir.'/blog-ai-'.($tag !== '' ? "{$tag}-" : '');
        $path = $prefix.now()->format('Ymd-His').'.sqlite';

        DB::statement('VACUUM INTO ?', [$path]);

        // 같은 종류끼리만 오래된 것을 지운다(정기 백업이 DB 변경 직전 백업을 밀어내지 않게)
        $backups = collect(File::glob($prefix.'*.sqlite'))
            ->filter(fn ($file) => $tag !== '' || preg_match('/blog-ai-\d{8}-\d{6}\.sqlite$/', $file))
            ->sort()->values();
        $backups->slice(0, max(0, $backups->count() - (int) $this->option('keep')))->each(fn ($old) => File::delete($old));

        Log::info('database_backup', ['path' => $path, 'bytes' => filesize($path)]);
        $this->info("백업: {$path}");

        return self::SUCCESS;
    }
}
