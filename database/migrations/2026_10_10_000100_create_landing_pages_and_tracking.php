<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('capture_settings')->nullable()->after('capture_token');
        });

        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug', 80);
            $table->string('status')->default('draft');
            $table->string('color', 9)->default('#dc2626');
            $table->string('description', 300)->nullable();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->json('blocks')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('tracking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('meta_pixel_id', 32)->nullable();
            $table->string('tiktok_pixel_id', 40)->nullable();
            $table->string('google_tag_id', 32)->nullable();
            $table->string('google_ads_label', 80)->nullable();
            $table->text('credentials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_settings');
        Schema::dropIfExists('landing_pages');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('capture_settings');
        });
    }
};
