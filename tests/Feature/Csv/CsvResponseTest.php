<?php

namespace Miraqceed\Foundation\Tests\Feature\Csv;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Miraqceed\Foundation\Csv\CsvResponse;
use Miraqceed\Foundation\Tests\TestCase;

class CsvResponseTest extends TestCase
{
    private function body($response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_download_streams_with_utf8_filename(): void
    {
        $r = CsvResponse::download('企業一覧.csv', ['名前', '値'], [['a', '1'], ['b', '2']]);

        $this->assertStringContainsString("filename*=UTF-8''".rawurlencode('企業一覧.csv'), $r->headers->get('Content-Disposition'));
        $this->assertSame("\xEF\xBB\xBF名前,値\r\na,1\r\nb,2\r\n", $this->body($r));
    }

    public function test_failure_mid_stream_appends_incomplete_line(): void
    {
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
        {
            public function __construct(public array &$reported) {}

            public function report($e) { $this->reported[] = $e; }

            public function shouldReport($e) { return true; }

            public function render($request, $e) {}

            public function renderForConsole($output, $e) {}
        });

        $rows = (function () {
            yield ['a'];
            throw new Exception('boom');
        })();

        $out = $this->body(CsvResponse::download('x.csv', ['h'], $rows));

        $this->assertStringEndsWith("\"".CsvResponse::INCOMPLETE_LINE."\"\r\n", $out);
        $this->assertCount(1, $reported);
    }
}
