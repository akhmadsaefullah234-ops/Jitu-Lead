<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Same starters as App\Media\Library::FOLDERS, copied so this migration never changes. */
    private const FOLDERS = ['Price list', 'Foto rumah', 'Denah', 'Link Google Maps', 'Brosur', 'Lainnya'];

    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('media_folders')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable(); // when to use it; the AI reads this to choose what to send
            $table->string('kind', 10)->default('file'); // file | link
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'folder_id']);
        });

        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach (self::FOLDERS as $position => $name) {
                DB::table('media_folders')->insert(['tenant_id' => $tenantId, 'name' => $name, 'position' => $position + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('media_folders');
    }
};
