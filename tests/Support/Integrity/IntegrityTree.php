<?php

namespace Miraqceed\Foundation\Tests\Support\Integrity;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\IntegrityChecker;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\Report;
use Miraqceed\Foundation\Integrity\ViewsProcess;

/**
 * 試しの木：コンテナの Linux のファイルシステム（sys_get_temp_dir() の下）に、アプリの形の木を作り、根・storage・public・
 * 画面の置き場所をそこへ向ける（Windows のドライブを写した bind mount の上では、リンク・権限・FIFO が本番と同じに動かない）。
 * 終わりに、権限を戻してから消す
 */
trait IntegrityTree
{
    protected string $root;

    protected function setUpTree(): void
    {
        $this->root = sys_get_temp_dir().'/foundation-integrity-'.bin2hex(random_bytes(6));
        foreach (['public', 'app', 'config', 'routes', 'bootstrap/cache', 'vendor/acme/lib/src', 'resources/views', 'storage/app/public', 'storage/framework/views', 'storage/logs'] as $dir) {
            mkdir($this->root.'/'.$dir, 0775, true);
        }
        $this->writeFile('public/index.php', "<?php\n// front\n");
        $this->writeFile('public/robots.txt', "User-agent: *\n");
        $this->writeFile('app/Thing.php', "<?php\nclass Thing {}\n");
        $this->writeFile('config/app.php', "<?php\nreturn [];\n");
        $this->writeFile('routes/web.php', "<?php\n");
        $this->writeFile('bootstrap/app.php', "<?php\n");
        $this->writeFile('vendor/acme/lib/src/Lib.php', "<?php\nclass Lib {}\n");
        $this->writeFile('vendor/autoload.php', "<?php\n");
        $this->writeFile('artisan', "#!/usr/bin/env php\n<?php\n");
        $this->writeFile('.env', "APP_KEY=base64:dGVzdC1rZXktbm90LWEtc2VjcmV0LXRlc3Qta2V5IQ==\nSECRET_TOKEN=x-secret-value-x\n");
        $this->writeFile('composer.json', "{}\n");

        $this->app->useStoragePath($this->root.'/storage');
        $this->app->usePublicPath($this->root.'/public');
        config([
            'foundation.integrity.root' => $this->root,
            'foundation.integrity.snapshot' => null,
            'view.compiled' => $this->root.'/storage/framework/views',
            'view.paths' => [$this->root.'/resources/views'],
            'filesystems.links' => [],
        ]);
        // 画面の調べは、既定では親の中で流す（子プロセスの試しは、別の試しのクラスで）
        $this->app->instance(ViewsProcess::class, new ViewsProcess(available: false));
    }

    protected function tearDownTree(): void
    {
        Limits::overrideForTests(null);
        if (isset($this->root) && is_dir($this->root)) {
            exec('chmod -R u+rwx '.escapeshellarg($this->root).' 2>/dev/null; rm -rf '.escapeshellarg($this->root));
        }
    }

    protected function writeFile(string $path, string $contents): string
    {
        $full = $this->root.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0775, true);
        }
        file_put_contents($full, $contents);

        return $full;
    }

    protected function snapshot(): int
    {
        return Artisan::call('foundation:integrity-snapshot');
    }

    protected function check(): Report
    {
        return $this->app->make(IntegrityChecker::class)->run();
    }

    /** @return list<string> 種類ごとの道（'changed:vendor/a.php' の形） */
    protected function findings(Report $report): array
    {
        return array_map(fn (array $f) => $f[0]->value.':'.$f[1], $report->findings());
    }

    /** @return list<string> */
    protected function gaps(Report $report): array
    {
        return array_map(fn (array $g) => $g[0]->value.($g[1] === '' ? '' : ':'.$g[1]), $report->gaps());
    }

    protected function assertFinding(Report $report, Finding $finding, string $path): void
    {
        $this->assertContains($finding->value.':'.$path, $this->findings($report), implode("\n", $this->findings($report)));
    }

    protected function assertNoFinding(Report $report, Finding $finding, string $path): void
    {
        $this->assertNotContains($finding->value.':'.$path, $this->findings($report));
    }

    protected function assertGap(Report $report, Gap $gap): void
    {
        $this->assertTrue($report->hasGap($gap), 'gaps: '.implode(', ', $this->gaps($report)));
    }

    protected function requireNotRoot(): void
    {
        $this->assertNotSame(0, posix_geteuid(), '権限の試しは root でない利用者で流す（root は権限を無視する）');
    }

    /** 測った値を残す（PR の本文の記録のため。sys_get_temp_dir()/integrity-measurements.log に1行足す） */
    protected function measured(string $line): void
    {
        file_put_contents(sys_get_temp_dir().'/integrity-measurements.log', trim($line)."\n", FILE_APPEND);
    }

    /**
     * 子プロセスで流し、時間の上限で殺す（止まりうる・落ちうる試し）。子の memory_limit を決められる
     *
     * @param  Closure(): mixed  $work  子の中で流す。戻り値は serialize して親に返す
     * @return array{exit: int, signaled: bool, result: mixed, seconds: float}
     */
    protected function inChild(Closure $work, int $seconds = 20, string $memory = '128M'): array
    {
        $file = tempnam(sys_get_temp_dir(), 'foundation-integrity-child-');
        $started = microtime(true);
        $pid = pcntl_fork();
        if ($pid === 0) {
            ini_set('memory_limit', $memory);
            // 落ちたときの文を出さない（別のプロセスで流す試しでは、標準エラーへの出力が試しの誤りになる）
            ini_set('display_errors', '0');
            try {
                $result = $work();
                file_put_contents($file, serialize(['ok' => true, 'result' => $result, 'peak' => memory_get_peak_usage()]));
                exit(0);
            } catch (\Throwable $e) {
                file_put_contents($file, serialize(['ok' => false, 'result' => $e::class.': '.$e->getMessage()]));
                exit(2);
            }
        }
        $status = 0;
        $deadline = microtime(true) + $seconds;
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) > $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                @unlink($file);

                return ['exit' => -1, 'signaled' => true, 'result' => 'killed after '.$seconds.'s', 'seconds' => microtime(true) - $started];
            }
            usleep(20_000);
        }
        $data = @unserialize((string) @file_get_contents($file));
        @unlink($file);

        return [
            'exit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
            'signaled' => pcntl_wifsignaled($status),
            'result' => is_array($data) ? $data : null,
            'seconds' => microtime(true) - $started,
        ];
    }
}
