<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->text('system_prompt');
            $table->text('user_template');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
    }
};
