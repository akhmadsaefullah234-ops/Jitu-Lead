<?php

namespace App\Backup;

use Symfony\Component\Process\Process;

/** Runs the external tools the backup needs (pg_dump, tar, rclone) without a shell. Swapped for a fake in tests. */
class Shell
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  extra environment, e.g. PGPASSWORD
     * @param  ?callable(string): void  $onOutput  receives stdout as it arrives (to stream a dump into a file)
     * @return array{int, string} exit code and the tail of stderr
     */
    public function run(array $command, array $env = [], ?callable $onOutput = null, int $timeout = 3600): array
    {
        $process = new Process($command, null, $env ?: null, null, $timeout);
        $stderr = '';

        $process->run(function (string $type, string $buffer) use ($onOutput, &$stderr) {
            if ($type === Process::OUT) {
                $onOutput && $onOutput($buffer);
            } else {
                $stderr = substr($stderr.$buffer, -2000);
            }
        });

        return [$process->getExitCode() ?? 1, trim($stderr)];
    }

    public function exists(string $binary): bool
    {
        [$code] = $this->run(['sh', '-c', 'command -v '.escapeshellarg($binary)], [], null, 10);

        return $code === 0;
    }
}
