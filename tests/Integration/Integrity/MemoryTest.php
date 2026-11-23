<?php

namespace Miraqceed\Foundation\Tests\Integration\Integrity;

use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * メモリーの上限：子プロセス（memory_limit 128M。Laravel を起動した上で）で、外の者が決める数を大きくしても落ちないこと。
 * 落ちる（致命的な誤り）と子は結果を書かずに終わり、試しが落ちる。最大のメモリーを書き出す（PR の本文の記録のため）
 */
#[RunTestsInSeparateProcesses]
class MemoryTest extends TestCase
{
    use IntegrityTree;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl がない');
        }
        $this->setUpTree();
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    public function test_a_huge_list_of_short_lines_is_read_line_by_line_without_running_out(): void
    {
        $this->snapshot();
        $path = Snapshot::path();
        $head = fgets(fopen($path, 'r'));
        $out = fopen($path.'.big', 'w');
        fwrite($out, $head);
        $hash = hash_init('sha256');
        $count = 900_000;
        for ($i = 0; $i < $count; $i++) {
            $line = 'F '.bin2hex(sprintf('app/z/%07d', $i)).' '.str_repeat('0', 64)."\n";
            hash_update($hash, $line);
            fwrite($out, $line);
        }
        fwrite($out, 'end '.$count.' '.hash_final($hash)."\n");
        fclose($out);
        rename($path.'.big', $path);
        $size = filesize($path);

        $result = $this->inChild(function () {
            $report = $this->check();

            return ['removed' => $report->findingCounts()['removed'] ?? 0, 'kept' => count($report->findings()), 'peak' => memory_get_peak_usage()];
        }, 120, '128M');

        $this->measured(sprintf("\n[memory] list %d lines %.1f MB: peak %.1f MB, %.1f s\n", $count, $size / 1048576, ($result['result']['result']['peak'] ?? 0) / 1048576, $result['seconds']));
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertSame($count, $result['result']['result']['removed']);
        $this->assertLessThanOrEqual(Limits::FINDINGS_KEPT, $result['result']['result']['kept']);
    }

    public function test_a_deep_and_wide_tree_is_stopped_by_the_stack_limit_not_by_memory(): void
    {
        $name = fn (int $i) => sprintf('%05d', $i).str_repeat('n', 195);
        $width = 20_000;
        for ($i = 0; $i < $width; $i++) {
            mkdir($this->root.'/app/'.$name($i));
        }
        for ($i = 0; $i < $width; $i++) {
            mkdir($this->root.'/app/'.$name(0).'/'.$name($i));
        }
        $this->writeFile('config/app.php', "<?php // changed\n");

        $result = $this->inChild(function () {
            $report = $this->check();

            return ['too_many' => $report->hasGap(Gap::TooMany), 'memory' => $report->hasGap(Gap::Memory), 'peak' => memory_get_peak_usage()];
        }, 180, '128M');

        $this->measured(sprintf("\n[memory] deep-wide %d x 2 (200-char names): peak %.1f MB, %.1f s\n", $width, ($result['result']['result']['peak'] ?? 0) / 1048576, $result['seconds']));
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertTrue($result['result']['result']['too_many']);
    }

    public function test_a_long_chain_with_many_folders_at_the_bottom_does_not_run_out(): void
    {
        foreach (['storage/app/chain', 'storage/app/public/chain', 'app/chain'] as $base) {
            $path = $this->root.'/'.$base;
            for ($i = 0; $i < 150; $i++) {
                $path .= '/'.str_repeat('c', 24).($i % 10);
            }
            mkdir($path, 0775, true);
            for ($i = 0; $i < 30_000; $i++) {
                mkdir($path.'/b'.$i);
            }
        }
        config(['filesystems.links' => [$this->root.'/public/storage' => $this->root.'/storage/app/public']]);

        $result = $this->inChild(function () {
            $report = $this->check();

            return ['memory' => $report->hasGap(Gap::Memory), 'peak' => memory_get_peak_usage()];
        }, 240, '128M');

        $this->measured(sprintf("\n[memory] long chain 150 x 25 + 30000 folders x 3: peak %.1f MB, %.1f s\n", ($result['result']['result']['peak'] ?? 0) / 1048576, $result['seconds']));
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        // 段ごとの枠で持つので、網（最後の守り）に頼らずに収まる
        $this->assertFalse($result['result']['result']['memory']);
    }

    public function test_the_safety_net_stops_before_running_out_when_the_own_limits_are_removed(): void
    {
        Limits::overrideForTests(['n' => 100_000_000, 'depth' => 100_000]);
        // 網がなければ 128M を超える数（名前だけで約 100MB）。網は 60%（約 77MB）で止める
        $width = 330_000;
        $base = $this->root.'/app/wide';
        mkdir($base);
        for ($i = 0; $i < $width; $i++) {
            touch($base.'/'.sprintf('%06d', $i).str_repeat('w', 240));
        }

        $result = $this->inChild(function () {
            $started = memory_get_usage();
            $report = $this->check();

            return ['memory' => $report->hasGap(Gap::Memory), 'peak' => memory_get_peak_usage(), 'start' => $started];
        }, 240, '128M');

        $this->measured(sprintf("\n[memory] safety net, own limits off, 128M: start %.1f MB, peak %.1f MB, %.1f s\n", ($result['result']['result']['start'] ?? 0) / 1048576, ($result['result']['result']['peak'] ?? 0) / 1048576, $result['seconds']));
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertTrue($result['result']['result']['memory']);
    }

    public function test_memory_limit_minus_one_is_taken_as_one_gigabyte(): void
    {
        $result = $this->inChild(fn () => [Limits::memoryLimit(), Limits::n()], 10, '-1');

        $this->assertSame([1024 * 1024 * 1024, 200_000], $result['result']['result']);
    }
}
