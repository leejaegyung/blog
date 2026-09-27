<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('tone')->nullable();
            $table->unsignedInteger('target_length')->nullable();
            $table->json('content_json')->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_text')->nullable();
            $table->string('status')->default('draft');
            $table->string('generation_version')->nullable();
            $table->string('published_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
