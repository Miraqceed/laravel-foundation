<?php

namespace Miraqceed\Foundation\Tests\Feature\RequestId;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Miraqceed\Foundation\RequestId\AssignRequestId;
use Miraqceed\Foundation\RequestId\RequestId;
use Miraqceed\Foundation\Tests\Support\RequestIdProbeJob;
use Miraqceed\Foundation\Tests\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use RuntimeException;

class AssignRequestIdTest extends TestCase
{
    private TestHandler $logs;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.default', 'probe');
        $app['config']->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/ok', fn () => response()->json(['id' => RequestId::current(), 'headers' => RequestId::headers()]));
        $router->get('/boom', fn () => throw new RuntimeException('落ちた'));
        $router->get('/log', function () {
            Log::info('in request');

            return 'ok';
        });
        $router->get('/dispatch', function () {
            RequestIdProbeJob::dispatch()->onConnection('database');

            return 'ok';
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // アプリが bootstrap/app.php で全体の先頭に足す形
        $this->app->make(HttpKernel::class)->prependMiddleware(AssignRequestId::class);
        $this->logs = Log::channel('probe')->getLogger()->getHandlers()[0];
    }

    public function test_ULID_を作り_利用者の_X_Request_Id_を返さない(): void
    {
        $response = $this->getJson('/ok', ['X-Request-Id' => 'client-chosen']);

        $requestId = (string) $response->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUlid($requestId));
        $response->assertHeader('X-Correlation-Id', $requestId);
        $response->assertJson(['id' => $requestId, 'headers' => ['X-Request-Id' => $requestId, 'X-Correlation-Id' => $requestId]]);
    }

    public function test_形の合う相関の_id_は引き継ぎ_改行や記号を含むものは捨てる(): void
    {
        $this->get('/ok', ['X-Correlation-Id' => 'upstream-job:42'])->assertHeader('X-Correlation-Id', 'upstream-job:42');

        foreach (["bad\nvalue", 'a<script>', str_repeat('a', 129), ''] as $value) {
            $response = $this->get('/ok', ['X-Correlation-Id' => $value]);
            $this->assertSame($response->headers->get('X-Request-Id'), $response->headers->get('X-Correlation-Id'));
        }
    }

    public function test_誤りの応答にも見出しが付く(): void
    {
        $missing = $this->get('/missing');
        $missing->assertNotFound();
        $this->assertTrue(Str::isUlid((string) $missing->headers->get('X-Request-Id')));

        $boom = $this->get('/boom');
        $boom->assertStatus(500);
        $this->assertTrue(Str::isUlid((string) $boom->headers->get('X-Request-Id')));
    }

    public function test_ログの行に要求の_id_が付く(): void
    {
        $response = $this->get('/log');

        $record = $this->record('in request');
        $this->assertSame($response->headers->get('X-Request-Id'), $record->extra['request_id'] ?? null);
        $this->assertSame($response->headers->get('X-Correlation-Id'), $record->extra['correlation_id'] ?? null);
    }

    public function test_要求の中で入れたジョブのログにも同じ_id_が付く(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        try {
            $requestId = $this->get('/dispatch')->headers->get('X-Request-Id');
            // 要求の後で消す。ジョブの中の id は、ジョブといっしょに運ばれたものだけ
            Context::flush();
            // worker は止めた待ち行列をキャッシュで見る（この試しは cache の表を作らない）
            config(['cache.default' => 'array']);

            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertExitCode(0);
        } finally {
            Schema::dropIfExists('jobs');
        }

        $record = $this->record('job ran');
        $this->assertSame($requestId, $record->context['seen']);
        $this->assertSame($requestId, $record->extra['request_id'] ?? null);
    }

    public function test_中間を通らなければ_id_はない(): void
    {
        $this->assertNull(RequestId::current());
        $this->assertNull(RequestId::correlation());
        $this->assertSame([], RequestId::headers());
    }

    private function record(string $message): LogRecord
    {
        foreach ($this->logs->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail("ログに「{$message}」がない");
    }
}
