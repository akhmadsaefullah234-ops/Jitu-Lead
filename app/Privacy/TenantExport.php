<?php

namespace App\Privacy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Everything an agency owns, as one ZIP of CSV files (opens in Excel and Google
 * Sheets). Columns come from the database itself, minus the secrets listed
 * below, so a column added later is exported without remembering to touch this.
 */
class TenantExport
{
    /** File name => [table, columns that never leave the server]. Every table here has a tenant_id. */
    private const TABLES = [
        'lead.csv' => ['leads', []],
        'aktivitas-lead.csv' => ['lead_activities', []],
        'properti.csv' => ['properties', []],
        'tahap-penjualan.csv' => ['stages', []],
        'sumber-lead.csv' => ['lead_sources', []],
        'alasan-gugur.csv' => ['lost_reasons', []],
        'percakapan-whatsapp.csv' => ['wa_conversations', []],
        'pesan-whatsapp.csv' => ['wa_messages', []],
        'nomor-whatsapp.csv' => ['wa_channels', ['credentials', 'webhook_token']],
        'template-whatsapp.csv' => ['wa_templates', []],
        'aturan-follow-up.csv' => ['follow_up_rules', []],
        'riwayat-follow-up.csv' => ['follow_up_logs', []],
        'landing-page.csv' => ['landing_pages', []],
        'pengaturan-pelacakan.csv' => ['tracking_settings', ['credentials']],
        'pengetahuan-ai.csv' => ['ai_knowledge_items', []],
        'saran-ai.csv' => ['ai_suggestions', []],
        'draf-ai.csv' => ['ai_drafts', []],
        'chat-support.csv' => ['support_threads', []],
        'pesan-chat-support.csv' => ['support_messages', []],
        'permintaan-paket.csv' => ['subscription_requests', []],
    ];

    /** Returns the path of a temporary ZIP; the caller deletes it after sending. */
    public function __invoke(Tenant $tenant): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jitu-export-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP tidak bisa dibuat.');
        }

        $handles = [];
        $counts = [];

        $add = function (string $name, iterable $rows, array $columns) use ($zip, &$handles, &$counts) {
            $tmp = tempnam(sys_get_temp_dir(), 'jitu-csv-');
            $out = fopen($tmp, 'w+b');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ',', '"', '');
            $n = 0;

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => $this->cell($row->{$c} ?? null), $columns), ',', '"', '');
                $n++;
            }

            fclose($out);
            $zip->addFile($tmp, $name);
            $handles[] = $tmp;
            $counts[$name] = $n;
        };

        $tenantColumns = array_values(array_diff(Schema::getColumnListing('tenants'), ['capture_token']));
        $add('agensi.csv', DB::table('tenants')->where('id', $tenant->getKey())->get(), $tenantColumns);

        $subColumns = Schema::getColumnListing('subscriptions');
        $add('langganan.csv', DB::table('subscriptions')->where('tenant_id', $tenant->getKey())->get(), $subColumns);

        // Members: who they are and their role here; never the password.
        $add('anggota.csv', DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenant->getKey())
            ->select('users.id', 'users.name', 'users.email', 'tenant_user.role', 'tenant_user.status', 'users.email_verified_at', 'users.created_at')->orderBy('users.id')->get(),
            ['id', 'name', 'email', 'role', 'status', 'email_verified_at', 'created_at']);

        foreach (self::TABLES as $name => [$table, $exclude]) {
            $columns = array_values(array_diff(Schema::getColumnListing($table), $exclude));
            $add($name, DB::table($table)->where('tenant_id', $tenant->getKey())->lazyById(500), $columns);
        }

        $zip->addFromString('BACA-SAYA.txt', $this->readme($tenant, $counts));
        $zip->close();

        foreach ($handles as $tmp) {
            @unlink($tmp);
        }

        return $path;
    }

    /** One CSV cell: arrays as JSON, and text that a spreadsheet would run as a formula made harmless. */
    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }

    private function readme(Tenant $tenant, array $counts): string
    {
        $when = now()->timezone(config('app.timezone'))->format('d M Y H:i');
        $lines = collect($counts)->map(fn ($n, $file) => sprintf('- %s: %d baris', $file, $n))->implode("\n");

        return <<<TXT
        Ekspor data agensi "{$tenant->name}" dari JITU LEAD
        Dibuat: {$when}

        Berkas CSV memakai UTF-8 dan bisa dibuka di Excel atau Google Sheets.
        Kolom "id" menghubungkan antarberkas (mis. lead_id di aktivitas-lead.csv mengacu ke id di lead.csv).
        Kolom bertipe JSON (mis. isi blok landing page) ditulis sebagai teks JSON.
        Tidak disertakan: kata sandi, kunci/kredensial integrasi (WhatsApp, pelacakan), dan token formulir.
        Gambar landing page tidak ada di ZIP ini; alamatnya tertulis di landing-page.csv.

        Isi:
        {$lines}

        Berkas ini berisi data pribadi calon pembeli. Simpan dengan aman dan hapus bila tidak lagi diperlukan.
        TXT;
    }
}
