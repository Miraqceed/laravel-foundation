<?php

namespace Miraqceed\Foundation\Csv;

use Miraqceed\Foundation\Failures\FailureReporter;
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

        $fallback = preg_replace('/[^\x20-\x7E]|["\\\\%]/', '_', $filename);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($filename)
        );

        return $response;
    }
}
