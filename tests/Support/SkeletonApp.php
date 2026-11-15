<?php

namespace Miraqceed\Foundation\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * 試しのために、使い捨ての小さな Laravel のアプリを一時フォルダに作る。
 *
 * 依存はパッケージの vendor をそのまま使い（composer install をしない）、アプリの vendor には
 * 次の3つだけを置く：
 * - autoload.php：パッケージの vendor のクラスを読む。Pest の関数を読まないこともできる
 *   （collision の `artisan test` は Pest の関数があると Pest を起こすので、PHPUnit・paratest の経路を試すときは外す）
 * - composer/installed.json：collision とこのパッケージだけ。プロバイダーは本物のアプリと同じく auto-discovery で読まれる
 * - 起動の台本の写し（phpunit・paratest・Pest）：台本は自分の場所から autoload.php を探すので、写しを置くとこのアプリの autoload.php を読む
 */
final class SkeletonApp
{
    private function __construct(public readonly string $path) {}

    /**
     * @param  array<string, string>  $files  アプリの中の相対パス => 中身
     */
    public static function create(array $files = [], bool $withPest = false): self
    {
        $path = sys_get_temp_dir().'/foundation-skeleton-'.bin2hex(random_bytes(6));
        $app = new self($path);
        $vendor = dirname(__DIR__, 2).'/vendor';

        foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'database', 'probes'] as $dir) {
            mkdir("{$path}/{$dir}", 0777, true);
        }

        $app->write('artisan', <<<'PHP'
            <?php
            define('LARAVEL_START', microtime(true));
            require __DIR__.'/vendor/autoload.php';
            $status = (require_once __DIR__.'/bootstrap/app.php')->handleCommand(new Symfony\Component\Console\Input\ArgvInput);
            exit($status);
            PHP);

        $app->write('bootstrap/app.php', <<<'PHP'
            <?php
            use Illuminate\Foundation\Application;
            return Application::configure(basePath: dirname(__DIR__))
                ->withRouting(commands: __DIR__.'/../routes/console.php')
                ->withMiddleware(function ($middleware) {})
                ->withExceptions(function ($exceptions) {})
                ->create();
            PHP);

        $app->write('routes/console.php', <<<'PHP'
            <?php
            use Illuminate\Support\Facades\Artisan;
            use Illuminate\Support\Facades\DB;
            Artisan::command('probe:connect', function () {
                DB::connection()->select('select 1');
                $this->line('connected');
            });
            PHP);

        $app->write('database/migrations/2026_01_01_000000_create_items_table.php', <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('items', function (Blueprint $table) {
                        $table->id();
                    });
                }
            };
            PHP);

        $app->write('phpunit.xml', self::phpunitXml($path, []));
        // collision の printer は .env を読む（ないと各テストに警告が付く）。本物のアプリと同じく置く
        $app->write('.env', '');

        $skipPest = $withPest ? 'false' : 'true';
        $app->write('vendor/autoload.php', <<<PHP
            <?php
            \$vendor = '{$vendor}';
            require_once \$vendor.'/composer/ClassLoader.php';
            \$loader = new \\Composer\\Autoload\\ClassLoader(\$vendor);
            foreach (require \$vendor.'/composer/autoload_namespaces.php' as \$namespace => \$dirs) { \$loader->set(\$namespace, \$dirs); }
            foreach (require \$vendor.'/composer/autoload_psr4.php' as \$namespace => \$dirs) { \$loader->setPsr4(\$namespace, \$dirs); }
            \$loader->addClassMap(require \$vendor.'/composer/autoload_classmap.php');
            \$loader->addPsr4('Tests\\\\', __DIR__.'/../tests');
            \$loader->register(true);
            foreach (require \$vendor.'/composer/autoload_files.php' as \$id => \$file) {
                if ({$skipPest} && str_contains(\$file, '/pestphp/')) { continue; }
                if (empty(\$GLOBALS['__composer_autoload_files'][\$id])) {
                    \$GLOBALS['__composer_autoload_files'][\$id] = true;
                    require \$file;
                }
            }
            return \$loader;
            PHP);

        $installed = json_decode((string) file_get_contents($vendor.'/composer/installed.json'), true);
        $collision = array_values(array_filter(
            $installed['packages'] ?? $installed,
            fn (array $package) => $package['name'] === 'nunomaduro/collision',
        ));
        $self = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
        $app->write('vendor/composer/installed.json', json_encode(['packages' => [
            ...$collision,
            ['name' => $self['name'], 'extra' => $self['extra']],
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $app->write('vendor/phpunit/phpunit/phpunit', (string) file_get_contents($vendor.'/phpunit/phpunit/phpunit'));
        $app->write('vendor/brianium/paratest/bin/paratest', (string) file_get_contents($vendor.'/brianium/paratest/bin/paratest'));
        if ($withPest) {
            $app->write('vendor/pestphp/pest/bin/pest', (string) file_get_contents($vendor.'/pestphp/pest/bin/pest'));
        }

        foreach ($files as $name => $contents) {
            $app->write($name, $contents);
        }

        return $app;
    }

    /**
     * @param  array<string, string>  $forcedEnv  phpunit.xml で force して入れる環境変数
     */
    public static function phpunitXml(string $basePath, array $forcedEnv): string
    {
        $env = '';
        foreach ($forcedEnv as $name => $value) {
            $env .= "        <env name=\"{$name}\" value=\"{$value}\" force=\"true\"/>\n";
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="vendor/autoload.php" colors="false" cacheDirectory=".phpunit.cache">
                <testsuites>
                    <testsuite name="Feature">
                        <directory>tests/Feature</directory>
                    </testsuite>
                </testsuites>
                <php>
                    <env name="APP_ENV" value="testing"/>
                    <env name="APP_BASE_PATH" value="{$basePath}"/>
                    <env name="CACHE_STORE" value="array"/>
                    <env name="SESSION_DRIVER" value="array"/>
            {$env}    </php>
            </phpunit>
            XML;
    }

    public function write(string $name, string $contents): void
    {
        $file = "{$this->path}/{$name}";
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    /**
     * アプリのフォルダで動かす。環境変数は、この試しのプロセスのもの（CI の DB_*・Testbench や phpunit が入れたもの）を受け継がず、
     * 新しい端末と同じく PATH などだけから始め、渡したものを足す。
     *
     * @param  list<string>  $command  PHP の後ろに続ける引数
     * @param  array<string, string>  $env
     */
    public function run(array $command, array $env = []): Process
    {
        // Symfony の Process は親の環境変数を必ず受け継ぐので、消すものは false で渡す
        $inherited = array_keys(getenv() + $_ENV + array_filter($_SERVER, 'is_string'));
        $keep = ['PATH', 'HOME', 'TMPDIR', 'LANG', 'SYSTEMROOT'];
        $clean = array_fill_keys(array_diff($inherited, $keep), false);

        $process = new Process([PHP_BINARY, ...$command], $this->path, $env + [
            'PROBE_DIR' => $this->path.'/probes',
            // 例外の文を折り返さない（DB の名前が途中で切れて見つけられなくなる）
            'COLUMNS' => '1000',
        ] + $clean, null, 300);
        $process->run();

        return $process;
    }

    /**
     * 子のプロセスが残した印（テストの setUp が書く）
     *
     * @return list<string>
     */
    public function probes(): array
    {
        return glob($this->path.'/probes/*') ?: [];
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree("{$path}/{$entry}");
            }
        }
        rmdir($path);
    }
}
