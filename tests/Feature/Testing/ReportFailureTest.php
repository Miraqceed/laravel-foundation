<?php

namespace Miraqceed\Foundation\Tests\Feature\Testing;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;
use UnexpectedValueException;

class ReportFailureTest extends TestCase
{
    public function test_素の_report_が投げ_記録しようとした文が返る(): void
    {
        $attempts = ReportFailure::simulate();

        try {
            report(new RuntimeException('落ちた'));
            $this->fail('report() が投げなかった');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('could not be opened in append mode', $e->getMessage());
        }

        $this->assertSame(['落ちた'], $attempts->getArrayCopy());
    }

    public function test_Exceptions_fake_の中では止める(): void
    {
        Exceptions::fake();

        $this->expectException(LogicException::class);

        ReportFailure::simulate();
    }

    public function test_前の試しの場面は残らない_1(): void
    {
        ReportFailure::simulate();

        $this->expectException(UnexpectedValueException::class);
        report(new RuntimeException('落ちた'));
    }

    public function test_前の試しの場面は残らない_2(): void
    {
        // 1つ前の試しで足した reportable は、アプリを作り直すと消える（残っていれば、こちらより先に呼ばれて投げる）
        $seen = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$seen) {
            $seen[] = $e->getMessage();

            return false;
        });

        report(new RuntimeException('落ちた'));

        $this->assertSame(['落ちた'], $seen);
    }
}
