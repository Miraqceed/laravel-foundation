<?php

namespace Miraqceed\Foundation\Tests\Unit\Integrity;

use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\IntegrityFailure;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\PathOrder;
use Miraqceed\Foundation\Integrity\Report;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** 名前の出し方・件数の上限・並べ方・この部品の例外 */
class ReportTest extends TestCase
{
    public function test_display_writes_out_control_and_format_characters_and_backslashes(): void
    {
        $this->assertSame('a\nb', Report::display("a\nb"));
        $this->assertSame('a\\\\nb', Report::display('a\\nb'));
        $this->assertNotSame(Report::display("a\nb"), Report::display('a\\nb'));
        $this->assertSame('x\u{202E}gpj.php', Report::display("x\u{202E}gpj.php"));
        $this->assertSame('a\u{2028}b\tc\r', Report::display("a\u{2028}b\tc\r"));
        $this->assertSame('a?b', Report::display("a\xffb"));
    }

    public function test_for_alert_also_disarms_chat_markup(): void
    {
        $this->assertSame('public/&lt;!channel&gt;.php', Report::forAlert('public/<!channel>.php'));
        $this->assertSame('a &amp; b', Report::forAlert('a & b'));
        $this->assertSame('x\nlink', Report::forAlert("x\nlink"));
    }

    public function test_keeps_names_up_to_the_limits_and_counts_the_rest(): void
    {
        $report = new Report;
        for ($i = 0; $i < Limits::FINDINGS_KEPT + 50; $i++) {
            $report->add(Finding::Added, 'f'.$i);
        }
        for ($i = 0; $i < Limits::GAPS_KEPT + 20; $i++) {
            $report->notChecked(Gap::Unopened, 'd'.$i);
        }
        $report->notChecked(Gap::BrokenSnapshot);

        $this->assertCount(Limits::FINDINGS_KEPT, $report->findings());
        $this->assertSame(Limits::FINDINGS_KEPT + 50, $report->findingCount());
        $this->assertTrue($report->hasGap(Gap::BrokenSnapshot));
        $this->assertContains([Gap::BrokenSnapshot, ''], $report->gaps());
        $this->assertCount(Limits::GAPS_KEPT + 1, $report->gaps());
    }

    public function test_prints_each_line_when_found(): void
    {
        $lines = [];
        $report = new Report(function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $report->add(Finding::Changed, "a\nb");
        $report->notChecked(Gap::TimedOut, 'tree');

        $this->assertSame(['[changed] a\nb', '[not checked] timed-out (tree)'], $lines);
    }

    public function test_the_fingerprint_changes_with_the_set(): void
    {
        $a = new Report;
        $a->add(Finding::Added, 'x');
        $b = new Report;
        $b->add(Finding::Added, 'x');
        $c = new Report;
        $c->add(Finding::Added, 'y');

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
    }

    public function test_path_order_is_strict_byte_order_with_files_before_folders_and_top_groups(): void
    {
        $paths = ['public/index.php', 'public/a/b.php', 'bootstrap/app.php', '.env', 'artisan', '.git/config', 'app/9', 'app/10', 'app/1e1', 'app/x/y', 'vendor/autoload.php'];
        usort($paths, [PathOrder::class, 'compare']);

        $this->assertSame(['public/index.php', 'public/a/b.php', 'bootstrap/app.php', '.env', 'artisan', '.git/config', 'app/10', 'app/1e1', 'app/9', 'app/x/y', 'vendor/autoload.php'], $paths);
        // vendor で終わる道（リンクに替えた vendor）は、根の直下で終わる道の組
        $this->assertGreaterThan(0, PathOrder::compare('vendor', 'public/index.php'));
        $this->assertLessThan(0, PathOrder::compare('vendor', '.git/config'));
        $this->assertGreaterThan(0, PathOrder::compare('vendor/a', 'vendor'));
        $this->assertSame(0, PathOrder::compare('a/b', 'a/b'));
    }

    public function test_the_failure_carries_only_the_check_and_the_class_name(): void
    {
        $failure = IntegrityFailure::caught('tree', new RuntimeException('opendir(/home/someone/x): failed'));

        $this->assertStringNotContainsString('/home/someone', $failure->getMessage());
        $this->assertStringContainsString('RuntimeException', $failure->getMessage());
        $this->assertNull($failure->getPrevious());
    }
}
