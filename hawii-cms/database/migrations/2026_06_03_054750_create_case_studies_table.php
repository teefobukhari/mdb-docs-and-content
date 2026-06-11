<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_studies', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('cover_image')->nullable();
            $table->json('client')->nullable();          // {en, ar}
            $table->json('title');                       // {en, ar}
            $table->json('summary')->nullable();         // {en, ar}
            $table->json('body')->nullable();            // {en, ar} rich content
            $table->json('industry')->nullable();        // {en, ar} free-text tag
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_studies');
    }
};
