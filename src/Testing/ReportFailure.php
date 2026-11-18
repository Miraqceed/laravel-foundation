<?php

namespace Miraqceed\Foundation\Testing;

use ArrayObject;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Testing\Fakes\ExceptionHandlerFake;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * 試しの助け：report() が投げる場面を作る（ログのファイルを開けないときと同じ）。
 * 「記録に失敗しても本筋は止まらない」ことを、本物の例外の扱い（ハンドラー）のまま確かめる。
 *
 * ハンドラーに、いつも投げる reportable を足す。ハンドラーは試しごとにアプリと一緒に作り直されるので、戻す手間は要らない。
 * Exceptions::fake() の中では report() が投げないので、場面を作れない。そのときは止める（黙って効かないことを防ぐ）。
 * 本番のコードからは呼ばない。
 */
final class ReportFailure
{
    /**
     * @return ArrayObject<int, string> 記録しようとした例外の文（呼ばれた順）
     */
    public static function simulate(): ArrayObject
    {
        $handler = app(ExceptionHandler::class);

        if ($handler instanceof ExceptionHandlerFake) {
            throw new LogicException('Exceptions::fake() の中では report() が投げないので、ReportFailure::simulate() の場面を作れません。fake() を外してください');
        }

        if (! method_exists($handler, 'reportable')) {
            throw new LogicException('例外の扱いが Laravel の Handler でないので、ReportFailure::simulate() の場面を作れません');
        }

        /** @var ArrayObject<int, string> $attempts */
        $attempts = new ArrayObject;

        $handler->reportable(function (Throwable $e) use ($attempts) {
            $attempts[] = $e->getMessage();

            // Monolog の StreamHandler がファイルを開けないときと同じ形
            throw new UnexpectedValueException('The stream or file "'.storage_path('logs/laravel.log').'" could not be opened in append mode: Failed to open stream: Permission denied');
        });

        return $attempts;
    }
}
