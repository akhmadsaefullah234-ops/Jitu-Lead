<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - landing_pages.ab_test: a built-in A/B test from this page (A) against another (B), served 50:50 at
 *   /p/{agency}/{A}?v=auto ({enabled, partner_id, spend_a, spend_b}); ab_views counts the visits served that way.
 * - leads.lead_form_id, landing_page_id, ab_variant: where a lead came from, for the report per page and variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->json('ab_test')->nullable();
            $table->unsignedInteger('ab_views')->default(0);
        });

        Schema::table('leads', function (Blueprint $table) {
            // Plain ids (no foreign key): a deleted form or page leaves the number, which the report simply no longer finds.
            $table->unsignedBigInteger('lead_form_id')->nullable();
            $table->unsignedBigInteger('landing_page_id')->nullable();
            $table->string('ab_variant', 1)->nullable();
            $table->index(['tenant_id', 'landing_page_id', 'ab_variant']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'landing_page_id', 'ab_variant']);
            $table->dropColumn(['lead_form_id', 'landing_page_id', 'ab_variant']);
        });

        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn(['ab_test', 'ab_views']);
        });
    }
};
