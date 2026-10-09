<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only rows the owner has edited or marked reviewed; without a row the text comes from config/legal.php.
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('title', 120)->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
