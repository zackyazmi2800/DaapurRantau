<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_recipes', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id');
            $table->string('recipe_title', 1500);
            $table->json('recipe_data');
            $table->char('recipe_fingerprint', 64);
            $table->timestamps();
            $table->unique(['visitor_id', 'recipe_fingerprint']);
            $table->index(['visitor_id', 'created_at']);
        });
        Schema::create('recipe_searches', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id');
            $table->json('ingredients');
            $table->json('preferences');
            $table->unsignedTinyInteger('recipe_count');
            $table->uuid('snapshot_id');
            $table->timestamps();
            $table->index(['visitor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_searches');
        Schema::dropIfExists('saved_recipes');
    }
};
