<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 짝 글: 같은 경험을 네이버·티스토리에 동시에 올릴 때, 다른 플랫폼용 글을 따로 쓴다(같은 글을 두 곳에 올리면 중복 문서로 본다).
 * 짝 글(twin)이 원래 글을 가리킨다. 원래 글을 지우면 짝 글은 혼자 남는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('twin_of_post_id')->nullable()->constrained('posts')->nullOnDelete();
            // 원래 글에서 마지막으로 가져온 사실·사진 구성(바뀌었을 때만 다시 쓴다)
            $table->string('twin_source_hash', 64)->nullable();
        });
        Schema::table('post_images', function (Blueprint $table) {
            // 짝 글 사진이 어느 원래 사진을 복사한 것인지(파일은 따로 복사해 서로 지워도 영향 없음)
            $table->unsignedBigInteger('source_image_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('post_images', fn (Blueprint $table) => $table->dropColumn('source_image_id'));
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('twin_of_post_id');
            $table->dropColumn('twin_source_hash');
        });
    }
};
