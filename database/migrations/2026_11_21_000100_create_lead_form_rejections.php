<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Public form submissions turned away as robots (App\LeadForms\SpamGuard): only when, why and which form, never
 * who, so an owner can see on the Formulir list whether anything is being filtered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_form_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_form_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'lead_form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_rejections');
    }
};
