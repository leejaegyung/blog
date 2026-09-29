<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 네이버·티스토리 분리: 키워드·학습 카테고리와 글에 올릴 곳(platform)을 둔다.
 * 같은 키워드라도 네이버용·티스토리용 분석이 따로 있다(가이드·해시태그·학습 카테고리가 다르다).
 * 컬럼 추가·인덱스 교체만 한다(표를 다시 만들지 않는다). 기존 데이터는 모두 naver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->string('platform')->default('naver');
        });
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kind', 'keyword']);
            $table->unique(['user_id', 'kind', 'platform', 'keyword']);
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->string('platform')->default('naver');
            $table->string('tistory_url', 500)->nullable();
            $table->timestamp('tistory_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['platform', 'tistory_url', 'tistory_published_at']));
        Schema::table('keyword_projects', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kind', 'platform', 'keyword']);
            $table->unique(['user_id', 'kind', 'keyword']);
        });
        Schema::table('keyword_projects', fn (Blueprint $table) => $table->dropColumn('platform'));
    }
};
