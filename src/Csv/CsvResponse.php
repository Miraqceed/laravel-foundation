<?php

namespace Miraqceed\Foundation\Csv;

use Miraqceed\Foundation\Failures\FailureReporter;
use Miraqceed\Foundation\Files\FileName;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CsvResponse
{
    public const INCOMPLETE_LINE = '※ 書き出しが途中で失敗しました。このファイルは不完全です。';

    /**
     * 全体を組み立てずに流す。
     *
     * @param  array<int, mixed>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows, Encoding $encoding = Encoding::Utf8Bom): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($header, $rows, $encoding) {
            $out = fopen('php://output', 'w');
            $writer = new CsvWriter($out, $encoding);

            try {
                $writer->writeRow($header);

                foreach ($rows as $row) {
                    $writer->writeRow($row);
                }
            } catch (Throwable $e) {
                // 途中で失敗したことを、読む人に分かる形でファイルに残す
                try {
                    $writer->writeRow([self::INCOMPLETE_LINE]);
                } catch (Throwable) {
                }

                app(FailureReporter::class)->report($e);
            } finally {
                fclose($out);
            }
        }, 200, ['Content-Type' => 'text/csv']);

        // 名前の扱いは FileName の1か所：使えない字（/ など）を _ にし、200バイトに収め、
        // ASCII の代わりの名前（意味がなければ download.csv）と UTF-8 の名前（filename*）を付ける
        $response->headers->set('Content-Disposition', FileName::disposition($filename));

        return $response;
    }
}
