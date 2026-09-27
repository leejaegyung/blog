<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // 게시 전 품질 검사 결과(기획서 7장). 내부 점수이며 순위 보장이 아니다
            $table->json('quality_json')->nullable()->after('draft_error');
            $table->timestamp('quality_checked_at')->nullable()->after('quality_json');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['quality_json', 'quality_checked_at']);
        });
    }
};
