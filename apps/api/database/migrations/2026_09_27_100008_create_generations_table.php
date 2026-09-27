<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // 키워드 분석 호출은 글과 무관하므로 둘 다 nullable
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status')->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
