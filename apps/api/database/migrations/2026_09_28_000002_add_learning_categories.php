<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 카테고리별 학습: 프로젝트를 "키워드"(글마다 자동 생성)와 "학습 카테고리"(사용자가 만들고 참고 글 URL로 학습)로 나눈다.
 * 키워드 프로젝트는 고른 학습 카테고리(learning_category_id)의 참고 글 특징을 키워드 분석에 함께 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->string('kind')->default('keyword')->index();
            $table->foreignId('learning_category_id')->nullable()->constrained('keyword_projects')->nullOnDelete();
        });
        Schema::table('keyword_projects', function (Blueprint $table) {
            // 같은 이름의 키워드와 카테고리가 따로 있을 수 있다
            $table->dropUnique(['user_id', 'keyword']);
            $table->unique(['user_id', 'kind', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kind', 'keyword']);
            $table->unique(['user_id', 'keyword']);
        });
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learning_category_id');
            $table->dropColumn('kind');
        });
    }
};
