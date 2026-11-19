<?php

namespace Miraqceed\Foundation\Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Miraqceed\Foundation\Tests\TestCase;

class LimitBodySizeTest extends TestCase
{
    // 本物の multipart の要求は、この見出しを持つ（試しの call() は付けない）
    private const MULTIPART = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'];

    protected function defineRoutes($router): void
    {
        // 上限の後で、コントローラーが本文を読めるか
        $echo = fn (Request $request) => response()->json([
            'json' => $request->json()->all(),
            'length' => strlen($request->getContent()),
        ]);
        $router->post('/limited', $echo)->middleware('foundation.body-limit:20');
        $router->post('/broken-limit', $echo)->middleware('foundation.body-limit:abc');
        $router->post('/upload', fn (Request $request) => response()->json([
            'name' => $request->input('name'),
            'file' => $request->file('doc')?->getSize(),
        ]))->middleware('foundation.body-limit:600');
    }

    /** @param  array<string, string>  $server */
    private function postBody(string $uri, mixed $content, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content);
    }

    public function test_名乗った大きさが上限を超えれば断る(): void
    {
        // 本文は小さいが、名乗った大きさで判断する（本文を読まない）
        $this->postBody('/limited', '{"a":1}', ['CONTENT_LENGTH' => '1000'])->assertStatus(413);
    }

    public function test_名乗った大きさで断るときは本文を読まない(): void
    {
        CountingStream::register();
        $stream = fopen('foundation-counting://body', 'r');

        // JSON でない本文（JSON の本文は、Laravel が要求を作るときに読む。LimitBodySizeOrderTest）
        $this->postBody('/limited', $stream, ['CONTENT_LENGTH' => '1000', 'CONTENT_TYPE' => 'text/plain'])->assertStatus(413);

        $this->assertSame(0, CountingStream::$reads);
        fclose($stream);
    }

    public function test_名乗らない本文は実際の長さで断り_ちょうど上限は通す(): void
    {
        $this->postBody('/limited', '{"a":"1234567890123"}')->assertStatus(413); // 21 バイト

        // 20 バイト：通り、コントローラーが json() と getContent() を読める
        $this->postBody('/limited', '{"a":"123456789012"}')
            ->assertOk()
            ->assertExactJson(['json' => ['a' => '123456789012'], 'length' => 20]);
    }

    public function test_小さく名乗った本文も実際の長さで断る(): void
    {
        $this->postBody('/limited', '{"a":"123456789012345"}', ['CONTENT_LENGTH' => '5'])->assertStatus(413);
    }

    public function test_名乗った本文も上限の後で読める(): void
    {
        $this->postBody('/limited', '{"a":"x"}', ['CONTENT_LENGTH' => '9'])
            ->assertOk()
            ->assertExactJson(['json' => ['a' => 'x'], 'length' => 9]);
    }

    public function test_ストリームで持つ本文も上限の後で読める(): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, '{"a":"y"}');
        rewind($stream);

        $this->postBody('/limited', $stream)->assertOk()->assertExactJson(['json' => ['a' => 'y'], 'length' => 9]);

        $big = fopen('php://temp', 'r+');
        fwrite($big, '{"a":"'.str_repeat('z', 100).'"}');
        rewind($big);
        $this->postBody('/limited', $big)->assertStatus(413);
    }

    public function test_413_の形はアプリの例外の扱い(): void
    {
        $this->postBody('/limited', str_repeat('a', 30))->assertStatus(413)->assertJson(['message' => 'Payload Too Large']);

        $html = $this->call('POST', '/limited', [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('a', 30));
        $html->assertStatus(413);
        $this->assertStringStartsWith('text/html', (string) $html->headers->get('Content-Type'));
    }

    public function test_記録に本文とクエリ文字列を入れない(): void
    {
        Log::spy();

        $this->postBody('/limited?token=secret-token-value', '{"password":"secret-body-value"}')->assertStatus(413);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context);

            return $message === 'foundation.body_limit'
                && $context === ['path' => 'limited', 'bytes' => 32, 'limit' => 20]
                && ! str_contains($encoded, 'secret');
        });
    }

    public function test_引数の書き損じは本番でない環境で例外(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);

        $this->postBody('/broken-limit', '{}');
    }

    public function test_引数の書き損じは本番では通して記録する(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Log::spy();

        $this->postBody('/broken-limit', '{"a":1}')->assertOk();

        Log::shouldHaveReceived('warning')->once()->with('foundation.body_limit.invalid', ['limit' => 'abc']);
    }

    public function test_multipart_は受け取ったファイルと欄で測る(): void
    {
        $small = UploadedFile::fake()->createWithContent('a.txt', str_repeat('x', 100));
        $this->call('POST', '/upload', ['name' => '山田'], [], ['doc' => $small], self::MULTIPART)
            ->assertOk()
            ->assertExactJson(['name' => '山田', 'file' => 100]);

        $large = UploadedFile::fake()->createWithContent('b.txt', str_repeat('x', 700));
        $this->call('POST', '/upload', ['name' => '山田'], [], ['doc' => $large], self::MULTIPART)
            ->assertStatus(413);
    }

    public function test_multipart_は名乗った大きさがあればそれで断る(): void
    {
        $small = UploadedFile::fake()->createWithContent('a.txt', 'x');

        $this->call('POST', '/upload', [], [], ['doc' => $small], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'CONTENT_LENGTH' => '5000'])
            ->assertStatus(413);
    }
}

/** 読み取りの数を数えるストリーム（本文を読んだかを見る） */
class CountingStream
{
    public static int $reads = 0;

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public static function register(): void
    {
        self::$reads = 0;
        if (! in_array('foundation-counting', stream_get_wrappers(), true)) {
            stream_wrapper_register('foundation-counting', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        self::$reads++;
        $chunk = substr('{"a":1}', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 7;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $this->position = $offset;

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
