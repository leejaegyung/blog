<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 키워드 분석의 검색 노출 가이드(목표치·원칙·추천 해시태그·검사 기준)
        Schema::table('keyword_analyses', function (Blueprint $table) {
            $table->json('guide_json')->nullable()->after('insight_json');
        });
        // 사용자가 고친 해시태그 목록. null이면 최근 분석의 추천을 쓴다
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->json('hashtags_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_analyses', fn (Blueprint $table) => $table->dropColumn('guide_json'));
        Schema::table('keyword_projects', fn (Blueprint $table) => $table->dropColumn('hashtags_json'));
    }
};
