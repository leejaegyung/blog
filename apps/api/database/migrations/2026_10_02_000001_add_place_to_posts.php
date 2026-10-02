<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 글에 연결한 장소(지도 링크 + 카카오 로컬로 확인한 이름·주소·전화·업종·좌표). 사용자가 고른 장소만 저장한다 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('place_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn('place_json'));
    }
};
