<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 10)->default('running'); // running | ok | warning | failed
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('folder')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('steps')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
