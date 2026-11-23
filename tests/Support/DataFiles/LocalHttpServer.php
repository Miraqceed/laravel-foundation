<?php

namespace Miraqceed\Foundation\Tests\Support\DataFiles;

use RuntimeException;

/**
 * 試しの手元の HTTP の口（php -S を proc_open で立てる。127.0.0.1 だけで待ち受け、外へ出ない）。
 * 本物の HTTP の経路（cURL・見出し・本文の流し込み・切れた本文・遅い答え）を通すためのもの。
 *
 * 答え方は routes.json（道 → 状態・見出し・本文・遅れ・切り方）で決め、試しの途中で替えられる（要求ごとに読み直す）。
 * 届いた要求は requests.log に1行ずつ残す（数えるため）。止めるのは stop()（tearDown で必ず呼ぶ）
 */
final class LocalHttpServer
{
    /** @var resource|null */
    private $process = null;

    public readonly string $directory;

    public readonly int $port;

    /** @var array<string, array<string, mixed>> */
    private array $routes = [];

    public function __construct(int $workers = 4)
    {
        $this->directory = sys_get_temp_dir().'/foundation-data-files-http-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
        file_put_contents($this->directory.'/router.php', self::ROUTER);
        file_put_contents($this->directory.'/routes.json', '{}');
        touch($this->directory.'/requests.log');

        // 空いた番号を取る
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('空いた番号を取れない');
        }
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->directory.'/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory.'/out.log', 'w'], 2 => ['file', $this->directory.'/err.log', 'w']],
            $pipes,
            null,
            // 並んだ要求に答えるため（遅い答えの間に、ほかの要求に答える）
            ['PHP_CLI_SERVER_WORKERS' => (string) $workers],
        );
        if (! is_resource($this->process)) {
            throw new RuntimeException('php -S を立てられない');
        }

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($connection) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new RuntimeException('php -S が待ち受けない');
    }

    public function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    /**
     * 道の答え方を決める。
     *
     * @param  array{status?: int, headers?: array<string, string>, body?: string, file?: string, delay?: float,
     *     declare?: int, undeclared?: bool, truncate?: int, trickle?: array{0: int, 1: int}, probe?: array{0: string, 1: int}}  $answer
     *     probe：[ディレクトリ, バイト数]。本文の頭のバイト数を送った後に、そのディレクトリの名前の一覧を probes() に残す
     */
    public function route(string $path, array $answer): void
    {
        if (isset($answer['body'])) {
            // 本文はファイルにする（routes.json を大きくせず、バイナリーも JSON に入れない）
            $file = $this->directory.'/body-'.bin2hex(random_bytes(6));
            file_put_contents($file, $answer['body']);
            unset($answer['body']);
            $answer['file'] = $file;
        }
        $this->routes[$path] = $answer;
        $temporary = $this->directory.'/routes.json.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, json_encode($this->routes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        rename($temporary, $this->directory.'/routes.json');
    }

    /** @return list<array{m: string, p: string, ua: ?string, h: array<string, string>, t: float}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->directory.'/requests.log')));

        return array_values(array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    /** @return list<list<string>> route() の probe で残したディレクトリの名前の一覧（要求ごと） */
    public function probes(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents($this->directory.'/probe.log')));

        return array_values(array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
        }
        $this->process = null;
        if (is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->directory);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    private const ROUTER = <<<'PHP'
<?php
$dir = __DIR__;
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($dir.'/requests.log', json_encode([
    'm' => $_SERVER['REQUEST_METHOD'], 'p' => $_SERVER['REQUEST_URI'], 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'h' => $headers, 't' => microtime(true),
], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

$routes = json_decode((string) @file_get_contents($dir.'/routes.json'), true) ?: [];
$answer = $routes[$path] ?? ['status' => 404, 'body' => 'not found'];
if (! empty($answer['delay'])) {
    usleep((int) ($answer['delay'] * 1_000_000));
}
http_response_code($answer['status'] ?? 200);
foreach ($answer['headers'] ?? [] as $name => $value) {
    header("{$name}: {$value}");
}
$body = isset($answer['file']) ? (string) file_get_contents($answer['file']) : (string) ($answer['body'] ?? '');
if (isset($answer['declare'])) {
    header('Content-Length: '.$answer['declare']);
} elseif (empty($answer['undeclared'])) {
    header('Content-Length: '.strlen($body));
}
$send = isset($answer['truncate']) ? substr($body, 0, $answer['truncate']) : $body;
if (! empty($answer['probe'])) {
    // 本文の頭を送った後、受け手が書いている最中のディレクトリの中身を残す（一時のファイルの置き場所を確かめるため）
    [$probeDirectory, $head] = $answer['probe'];
    echo substr($send, 0, $head);
    @ob_flush();
    flush();
    usleep(500_000);
    file_put_contents($dir.'/probe.log', json_encode(array_values(array_diff(scandir($probeDirectory) ?: [], ['.', '..'])))."\n", FILE_APPEND | LOCK_EX);
    $send = substr($send, $head);
}
if (! empty($answer['trickle'])) {
    [$size, $micro] = $answer['trickle'];
    foreach (str_split($send, max(1, $size)) as $piece) {
        echo $piece;
        @ob_flush();
        flush();
        usleep($micro);
    }
} else {
    echo $send;
}
PHP;
}
