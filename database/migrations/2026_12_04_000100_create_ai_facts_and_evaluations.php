<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Structured property knowledge (App\Ai\Facts): one row per fact ("harga tipe Melati"), with where it came from (the
 * "Isi properti" wizard or an uploaded document) and whether the owner approved it. Facts read from a document wait
 * for review; two facts that disagree are shown to the owner to decide. The AI's standard knowledge entries are
 * written from the approved facts. Each AI evaluation run (ai:evaluate, "Uji AI saya", nightly) is kept for the
 * "Kesehatan AI" page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The unit type it is about ("Melati"), none for the project; property_id once that type is in the catalogue.
            $table->string('subject', 120)->nullable();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 40);
            $table->text('value');
            $table->string('source', 20);
            $table->string('source_name', 200)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->unsignedSmallInteger('passed');
            $table->unsignedSmallInteger('total');
            $table->json('results');
            // What the knowledge looked like (count|last change), so a change can be told from the next run.
            $table->string('knowledge_version', 80)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluations');
        Schema::dropIfExists('ai_facts');
    }
};
