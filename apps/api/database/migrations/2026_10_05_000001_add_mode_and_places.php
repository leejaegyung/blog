<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 글 성격(mode: info 정보 전달 / daily 일상 기록, null이면 카테고리로 자동)과 여러 장소.
 * place_json은 장소 하나(객체)였는데 이제 장소 목록(배열)을 담는다. 있던 장소는 목록 하나로 옮긴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('mode')->nullable();
        });
        foreach (DB::table('posts')->whereNotNull('place_json')->get(['id', 'place_json']) as $row) {
            $place = json_decode($row->place_json, true);
            if (is_array($place) && ! array_is_list($place)) {
                DB::table('posts')->where('id', $row->id)->update(['place_json' => json_encode([$place], JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};
