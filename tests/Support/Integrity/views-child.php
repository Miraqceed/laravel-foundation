<?php

/**
 * 試しの入り口：画面の調べの子プロセス（foundation:integrity-views）を、testbench のアプリで流す。
 * 子を落とす形・汚す形は、ここで環境の変数を読んで作る（パッケージのコードには継ぎ目を置かない）
 */

use Illuminate\Contracts\Console\Kernel;
use Miraqceed\Foundation\Commands\IntegrityViewsCommand;
use Miraqceed\Foundation\FoundationServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';

$mode = (string) getenv('INTEGRITY_CHILD_MODE');

if ($file = getenv('INTEGRITY_CHILD_ENV_FILE')) {
    file_put_contents($file, (string) getenv('FOUNDATION_TEST_MARK'));
}

switch ($mode) {
    case 'warning':
        // Laravel が誤りの扱いを付ける前の警告・非推奨（display_errors=stderr なら標準出力を汚さない）
        trigger_error('a warning before the head line', E_USER_WARNING);
        trigger_error('a deprecation before the head line', E_USER_DEPRECATED);
        break;
    case 'memory':
        ini_set('memory_limit', '32M');
        $hog = [];
        while (true) {
            $hog[] = str_repeat('x', 1024 * 1024);
        }
    case 'sleep':
        sleep(60);
        exit(0);
    case 'garbage':
        echo IntegrityViewsCommand::HEAD."\nnot a line\n";
        exit(0);
    case 'flood':
        echo IntegrityViewsCommand::HEAD."\n";
        $block = 'R view-content '.bin2hex('view.compiled/x.php')."\n";
        for ($i = 0; $i < 2_000_000; $i++) {
            echo $block;
        }
        exit(0);
    case 'stderr-flood':
        $block = str_repeat('e', 1024 * 1024);
        for ($i = 0; $i < 100; $i++) {
            fwrite(STDERR, $block);
        }
        break;
    case 'bad-kind':
        echo IntegrityViewsCommand::HEAD."\nR evil-kind ".bin2hex('view.compiled/x.php')."\nend 1\n";
        exit(0);
    case 'outside':
        echo IntegrityViewsCommand::HEAD."\nR view-content ".bin2hex('view.compiled/../../.env')."\nend 1\n";
        exit(0);
    case 'stderr-path':
        fwrite(STDERR, "/home/someone/secret-path/x-stderr-value-x\n");
        break;
    case 'compile-crash':
        // 見積もりを外し、子の中のコンパイルで本当にメモリーを使い切らせる
        \Miraqceed\Foundation\Integrity\Limits::overrideForTests(['view_estimate' => 0]);
        ini_set('memory_limit', '96M');
        break;
    case 'sapi':
        IntegrityViewsCommand::$sapiForTests = 'cgi-fcgi';
        break;
    case 'exit1':
        echo IntegrityViewsCommand::HEAD."\n";
        exit(1);
}

$app = Application::create();
$app->register(FoundationServiceProvider::class);

exit($app->make(Kernel::class)->call('foundation:integrity-views'));
