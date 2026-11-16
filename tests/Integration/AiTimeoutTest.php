<?php

namespace Miraqceed\Foundation\Tests\Integration;

use Laravel\Ai\AiServiceProvider;
use Miraqceed\Foundation\Tests\TestCase;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Laravel AI SDK の `timeout:` が HTTP の層で効くことを、本物のサーバーで確かめる。
 * 5秒待ってから答えるサーバー（php -S）へ向け、timeout: 1 の呼び出しが3秒以内に例外で返ることを見る。
 * 設計の前提（呼び出しが長く固まって上限の確かめを塞がない）の確かめ。
 */
class AiTimeoutTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    private string $dir = '';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->dir !== '') {
            foreach (glob($this->dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_時間の打ち切りはHTTPの層で効く(): void
    {
        $this->dir = sys_get_temp_dir().'/foundation-ai-timeout-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir.'/router.php', '<?php sleep(5); header("Content-Type: application/json"); echo "{}";');

        // 空いた番号を取る
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $this->dir.'/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->dir.'/out.log', 'w'], 2 => ['file', $this->dir.'/err.log', 'w']],
            $pipes,
        );
        $this->assertIsResource($this->process);

        // 待ち受けるまで待つ（持ったのを確かめてから進める）
        $ready = false;
        for ($i = 0; $i < 50 && ! $ready; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                $ready = true;
            } else {
                usleep(100_000);
            }
        }
        $this->assertTrue($ready, 'php -S が起動しなかった');

        config([
            'ai.providers.anthropic.key' => 'test-key',
            'ai.providers.anthropic.url' => "http://127.0.0.1:{$port}/v1",
        ]);

        $started = microtime(true);
        $thrown = null;
        try {
            agent('You are a test.')->prompt('hello', provider: 'anthropic', model: 'claude-test', timeout: 1);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $elapsed = microtime(true) - $started;

        $this->assertNotNull($thrown, '打ち切られずに答えが返った');
        $this->assertLessThan(3.0, $elapsed, "打ち切りに {$elapsed} 秒かかった");
    }
}
