<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('pillar')->default('it');   // 'it' or 'ai'
            $table->string('icon')->nullable();         // lucide-style svg path data or key
            $table->json('title');                      // {en, ar}
            $table->json('excerpt')->nullable();        // {en, ar} short card text
            $table->json('body')->nullable();           // {en, ar} rich detail
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
