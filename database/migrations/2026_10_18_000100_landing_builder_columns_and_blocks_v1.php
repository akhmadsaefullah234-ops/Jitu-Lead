<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('landing_pages', 'font')) {
            Schema::table('landing_pages', function (Blueprint $table) {
                $table->string('font', 20)->default('modern');
                $table->string('logo')->nullable();
                $table->string('whatsapp_number', 30)->nullable();
                $table->string('meta_title', 120)->nullable();
                $table->string('og_image')->nullable();
                $table->string('template', 30)->nullable();
            });
        }

        // Older pages stored sections as {type, data}. The builder now stores
        // {type, version, props}; convert once so every page has one shape.
        DB::table('landing_pages')->orderBy('id')->each(function ($row) {
            $blocks = json_decode((string) $row->blocks, true);

            if (! is_array($blocks) || $blocks === []) {
                return;
            }

            $changed = false;
            $converted = [];

            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    $changed = true;

                    continue;
                }

                if (isset($block['version'], $block['props'])) {
                    $converted[] = $block;

                    continue;
                }

                $changed = true;
                $type = $block['type'] ?? null;
                $props = is_array($block['data'] ?? null) ? $block['data'] : [];

                if ($type === 'whatsapp') {
                    $type = 'cta';
                    $props['heading'] ??= '';
                }

                if ($type === 'text' && isset($props['body']) && ! isset($props['html'])) {
                    $paras = array_filter(preg_split('/\R{2,}/', trim((string) $props['body'])) ?: [], fn ($p) => trim($p) !== '');
                    $props['html'] = implode('', array_map(fn ($p) => '<p>'.nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), false).'</p>', $paras));
                    unset($props['body']);
                }

                $converted[] = ['type' => $type, 'version' => 1, 'props' => $props];
            }

            if ($changed) {
                DB::table('landing_pages')->where('id', $row->id)->update(['blocks' => json_encode($converted, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        // The new columns are dropped; converted section data stays in the new
        // shape, which the application keeps reading.
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn(['font', 'logo', 'whatsapp_number', 'meta_title', 'og_image', 'template']);
        });
    }
};
