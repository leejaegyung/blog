<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 학습(키워드 분석) 진행 단계: queued(대기) → ai(AI 정리 중) → null(끝). 화면의 진행 표시에 쓴다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->string('analysis_step')->nullable();
            $table->timestamp('analysis_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->dropColumn(['analysis_step', 'analysis_started_at']);
        });
    }
};
