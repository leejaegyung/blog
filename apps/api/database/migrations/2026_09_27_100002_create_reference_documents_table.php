<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_project_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->text('source_url')->nullable();
            $table->string('title')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            // 원문은 DB에 넣지 않고 스토리지 키만 둔다 (원문 장기 보관은 기본값이 아님)
            $table->string('raw_storage_key')->nullable();
            $table->string('content_hash', 64)->nullable()->index();
            $table->string('usage_permission');
            $table->string('parse_status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_documents');
    }
};
