<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// VACUUM INTO는 트랜잭션 안에서 못 돌려 RefreshDatabase 없이 임시 파일 DB로 확인한다
class BackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/blog-backup-test-'.uniqid();
        $db = $this->dir.'/live.sqlite';
        File::ensureDirectoryExists($this->dir.'/backups');
        touch($db);
        config(['database.connections.sqlite.database' => $db, 'app.backup_path' => $this->dir.'/backups']);
        DB::purge('sqlite');
        DB::statement('create table notes (body text)');
        DB::table('notes')->insert(['body' => '백업할 내용']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_backup_creates_consistent_copy_and_keeps_recent_ones(): void
    {
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
            touch("{$this->dir}/backups/blog-ai-{$stamp}.sqlite");
        }

        $this->artisan('app:backup', ['--keep' => 2])->assertSuccessful();

        $files = collect(File::glob("{$this->dir}/backups/blog-ai-*.sqlite"))->map(fn ($f) => basename($f))->sort()->values();
        $this->assertCount(2, $files);
        $this->assertSame('blog-ai-20260103-000000.sqlite', $files[0]);

        $copy = new \PDO('sqlite:'.$this->dir.'/backups/'.$files[1]);
        $this->assertSame('백업할 내용', $copy->query('select body from notes')->fetchColumn());
    }
}
