<?php

use App\Models\LeadForm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Form builder: many forms per agency, each at /f/{agency}/{form}. The single form an agency had before
 * (texts in tenants.capture_settings, link /f/{capture_token}) becomes its "Formulir utama"; the old link keeps
 * working and now shows that form. Also: per-page open events on landing pages, and default click events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->string('template', 40)->nullable();
            $table->json('design')->nullable();
            $table->json('fields')->nullable();
            $table->json('submit')->nullable();
            $table->json('after_submit')->nullable();
            $table->json('buttons')->nullable();
            $table->json('page_events')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('submissions')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::table('landing_pages', function (Blueprint $table) {
            $table->json('page_events')->nullable();
        });

        Schema::table('tracking_settings', function (Blueprint $table) {
            $table->json('button_events')->nullable();
        });

        $now = now();

        DB::table('tenants')->whereNotNull('capture_token')->orderBy('id')->each(function ($tenant) use ($now) {
            $settings = json_decode((string) $tenant->capture_settings, true) ?: [];
            $row = LeadForm::legacyAttributes(is_array($settings) ? $settings : []);

            DB::table('lead_forms')->insert(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $row) + [
                'tenant_id' => $tenant->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tracking_settings', fn (Blueprint $table) => $table->dropColumn('button_events'));
        Schema::table('landing_pages', fn (Blueprint $table) => $table->dropColumn('page_events'));
        Schema::dropIfExists('lead_forms');
    }
};
