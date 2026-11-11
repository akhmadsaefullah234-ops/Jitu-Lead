<?php

namespace App\Console\Commands;

use App\Tutorials\Chapter;
use App\Tutorials\Tutorials;
use Database\Seeders\AgensiDemoSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\TokoDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Takes the tutorial screenshots again from the demo agencies (see docs/tutorial.md): seeds Toko Demo and Agensi
 * Demo, serves the app, opens every step that has a "shot" in a headless browser (scripts/tutorial-capture.mjs),
 * and saves public/tutorial/{slug}/{step}.webp (and {step}-hp.webp for the phone view). Local and testing only.
 */
#[Signature('tutorial:capture {slug?* : hanya bab ini} {--no-seed : pakai data demo yang sudah ada} {--url= : aplikasi yang sudah berjalan, mis. http://127.0.0.1:8000} {--port=8123}')]
#[Description('Ambil ulang tangkapan layar tutorial dari Toko Demo dan Agensi Demo (hanya APP_ENV=local/testing)')]
class TutorialCaptureCommand extends Command
{
    public const DESKTOP = ['width' => 1280, 'height' => 800];

    public const PHONE = ['width' => 390, 'height' => 844];

    /** Width of the saved WebP; quality 0-100. */
    public const QUALITY = 78;

    public function handle(): int
    {
        if (! DemoSeeder::allowed()) {
            $this->error('tutorial:capture hanya boleh dijalankan di APP_ENV=local atau testing.');

            return self::FAILURE;
        }

        $only = (array) $this->argument('slug');
        $chapters = collect(Tutorials::all())->filter(fn (Chapter $c) => $only === [] || in_array($c->slug, $only, true));
        $tmp = storage_path('app/tutorial-capture');
        File::ensureDirectoryExists($tmp);
        $shots = [];

        foreach ($chapters as $chapter) {
            foreach ($chapter->steps as $i => $step) {
                if ($step['shot'] === []) {
                    continue;
                }

                foreach (array_filter(['' => true, '-hp' => ! empty($step['shot']['mobile'])]) as $suffix => $_) {
                    $shots[] = $this->shot($chapter, $i + 1, $step['shot'], $suffix, $tmp);
                }
            }
        }

        if ($shots === []) {
            $this->warn('Tidak ada langkah bergambar.');

            return self::SUCCESS;
        }

        if (! $this->option('no-seed')) {
            $this->call('db:seed', ['--class' => TokoDemoSeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => AgensiDemoSeeder::class, '--force' => true]);
        }

        $server = null;
        $base = rtrim((string) $this->option('url'), '/');

        if ($base === '') {
            $port = (int) $this->option('port');
            // PHP's own server rather than artisan serve, so the environment reaches the app: an array cache means no
            // login throttle (the script logs in afresh for every picture) and no cached dashboard numbers.
            // The server gets a clean environment (this command's own variables, such as SCRIPT_NAME, would confuse
            // the app); it reads .env itself.
            $env = array_map(fn () => false, array_filter($_SERVER + $_ENV, 'is_string'));
            $env = array_replace($env, array_filter(['PATH' => getenv('PATH'), 'HOME' => getenv('HOME'), 'SYSTEMROOT' => getenv('SYSTEMROOT')]),
                ['PHP_CLI_SERVER_WORKERS' => '4', 'CACHE_STORE' => 'array',
                    // A placeholder so the pages show AI as switched on; nothing in a capture calls the AI.
                    'ANTHROPIC_API_KEY' => 'tutorial-capture']);
            $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', public_path(), base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
                public_path(), $env);
            $server->setTimeout(null)->disableOutput()->start(); // its request log is not read: a full pipe would stall it
            $base = 'http://127.0.0.1:'.$port;
            $this->waitFor($base);
        }

        File::put($manifest = $tmp.'/manifest.json', json_encode(['base' => $base, 'shots' => $shots], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $browser = new Process(['node', base_path('scripts/tutorial-capture.mjs'), $manifest], base_path(), null, null, 900);
        $browser->run(fn ($type, $out) => $this->output->write($out));
        $server?->stop();

        $saved = 0;

        foreach ($shots as $shot) {
            if (is_file($shot['out'])) {
                $this->webp($shot['out'], public_path($shot['target']));
                @unlink($shot['out']);
                $saved++;
            }
        }

        $this->info("{$saved} dari ".count($shots).' gambar disimpan di public/tutorial.');

        return $browser->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> one entry of the manifest the browser script reads */
    private function shot(Chapter $chapter, int $number, array $spec, string $suffix, string $tmp): array
    {
        $tenant = $spec['tenant'] ?? ($chapter->industries === ['properti'] ? 'agensi-demo' : 'toko-demo');
        $as = $spec['as'] ?? 'admin';
        $path = (string) ($spec['page'] ?? '');
        $mobile = $suffix === '-hp';

        return [
            'id' => "{$chapter->slug}/{$number}{$suffix}",
            'email' => $as.'@'.$tenant.'.test',
            'password' => DemoSeeder::PASSWORD,
            'path' => str_starts_with($path, '/') ? $path : '/app/'.$tenant.($path !== '' ? '/'.$path : ''),
            'steps' => array_values((array) ($mobile && isset($spec['mobile_steps']) ? $spec['mobile_steps'] : ($spec['steps'] ?? []))),
            'highlight' => array_values((array) ($mobile && isset($spec['mobile_highlight']) ? $spec['mobile_highlight'] : ($spec['highlight'] ?? []))),
            'viewport' => $mobile ? self::PHONE : ($spec['viewport'] ?? self::DESKTOP),
            'mobile' => $mobile,
            'out' => $tmp.'/'.str_replace('/', '__', $chapter->slug.'/'.$number.$suffix).'.png',
            'target' => "tutorial/{$chapter->slug}/{$number}{$suffix}.webp",
        ];
    }

    private function webp(string $png, string $target): void
    {
        File::ensureDirectoryExists(dirname($target));
        $image = imagecreatefrompng($png);
        imagepalettetotruecolor($image);
        imagewebp($image, $target, self::QUALITY);
        imagedestroy($image);
    }

    private function waitFor(string $base): void
    {
        for ($i = 0; $i < 60; $i++) {
            if (@file_get_contents($base.'/up') !== false || @file_get_contents($base) !== false) {
                return;
            }

            usleep(250_000);
        }
    }
}
