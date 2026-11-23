<?php

namespace Miraqceed\Foundation\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Integrity\Deadline;
use Miraqceed\Foundation\Integrity\Entry;
use Miraqceed\Foundation\Integrity\Fingerprint;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Snapshot;
use Miraqceed\Foundation\Integrity\SnapshotReader;
use Miraqceed\Foundation\Integrity\SnapshotUnavailable;
use Miraqceed\Foundation\Tests\Support\Integrity\IntegrityTree;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 一覧の命令：穴のある一覧を書かない・一時のファイル + rename・書いている間に読んでも、前の一覧かそろった新しい一覧
 */
class SnapshotCommandTest extends TestCase
{
    use IntegrityTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTree();
    }

    protected function tearDown(): void
    {
        $this->tearDownTree();
        parent::tearDown();
    }

    public function test_writes_the_list_with_head_items_in_order_and_end(): void
    {
        $this->assertSame(0, $this->snapshot());
        $lines = file(Snapshot::path(), FILE_IGNORE_NEW_LINES);

        $this->assertStringStartsWith('foundation-integrity 1 {', $lines[0]);
        $head = json_decode(substr($lines[0], strlen('foundation-integrity 1 ')), true);
        $this->assertSame(['root', 'created_at', 'settings'], array_keys($head));
        $this->assertMatchesRegularExpression('/\Aend \d+ [0-9a-f]{64}\z/', end($lines));
        $this->assertStringNotContainsString($this->root, implode("\n", $lines));

        $reader = Snapshot::reader($this->root);
        $paths = [];
        while (($item = $reader->next()) !== null) {
            $paths[] = $item[0];
        }
        $this->assertTrue($reader->finished);
        $this->assertSame('public/index.php', $paths[0]);
        $this->assertContains('vendor/autoload.php', $paths);
        $this->assertSame(count($paths), (int) explode(' ', end($lines))[1]);
    }

    public function test_leaves_no_temporary_file_and_replaces_by_rename(): void
    {
        $this->snapshot();
        $inode = fileinode(Snapshot::path());
        $this->snapshot();

        $this->assertNotSame($inode, fileinode(Snapshot::path()));
        $leftovers = array_filter(scandir(dirname(Snapshot::path())), fn ($name) => str_starts_with($name, Snapshot::TEMPORARY_PREFIX));
        $this->assertSame([], array_values($leftovers));
    }

    public function test_writes_no_list_when_a_file_cannot_be_read_or_is_not_regular_or_a_folder_cannot_be_opened(): void
    {
        $this->requireNotRoot();
        $this->snapshot();
        $before = file_get_contents(Snapshot::path());

        chmod($this->writeFile('app/Locked.php', 'x'), 0000);
        $this->assertSame(1, $this->snapshot());
        $this->assertStringContainsString('app/Locked.php', Artisan::output());
        chmod($this->root.'/app/Locked.php', 0644);
        unlink($this->root.'/app/Locked.php');

        posix_mkfifo($this->root.'/app/pipe', 0644);
        $result = $this->inChild(fn () => $this->snapshot(), 10);
        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertSame(1, $result['result']['result']);
        unlink($this->root.'/app/pipe');

        mkdir($this->root.'/app/closed');
        chmod($this->root.'/app/closed', 0000);
        $this->assertSame(1, $this->snapshot());

        $this->assertSame($before, file_get_contents(Snapshot::path()));
        $leftovers = array_filter(scandir(dirname(Snapshot::path())), fn ($name) => str_starts_with($name, Snapshot::TEMPORARY_PREFIX));
        $this->assertSame([], array_values($leftovers));
    }

    public function test_stops_at_the_deadline_on_a_huge_sparse_file_and_writes_nothing(): void
    {
        $this->sparse('vendor/acme/huge.bin', 10 * 1024 * 1024 * 1024);
        config(['foundation.integrity.seconds' => 2]);

        $result = $this->inChild(fn () => $this->snapshot(), 30);

        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertSame(1, $result['result']['result']);
        $this->assertLessThan(15, $result['seconds']);
        $this->assertFileDoesNotExist(Snapshot::path());
    }

    public function test_fingerprint_stops_inside_a_huge_sparse_file(): void
    {
        $path = $this->sparse('vendor/acme/huge.bin', 10 * 1024 * 1024 * 1024);
        $entry = new Entry('vendor/acme/huge.bin', $path, Entry::FILE, lstat($path));

        $started = microtime(true);
        $result = Fingerprint::file($entry, new Deadline(1));

        $this->assertFalse($result);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function test_fingerprint_refuses_a_file_swapped_after_lstat(): void
    {
        $path = $this->writeFile('app/a.txt', 'a');
        $stat = lstat($path);
        // 前のファイルを別の名前で生かしておく（inode が使い回されないように）
        rename($path, $path.'.old');
        file_put_contents($path, 'b');
        $entry = new Entry('app/a.txt', $path, Entry::FILE, $stat);

        $this->assertNull(Fingerprint::file($entry, new Deadline(10)));
    }

    public function test_reading_while_writing_sees_the_old_or_the_new_list_whole(): void
    {
        $this->snapshot();
        $stop = sys_get_temp_dir().'/foundation-integrity-stop-'.bin2hex(random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === 0) {
            $bad = 0;
            $reads = 0;
            while (! file_exists($stop)) {
                try {
                    $reader = SnapshotReader::open(Snapshot::path(), Snapshot::rootFingerprint($this->root));
                    while ($reader->next() !== null) {
                    }
                    $reads++;
                    if (! $reader->finished) {
                        $bad++;
                    }
                } catch (SnapshotUnavailable) {
                    $bad++;
                }
            }
            file_put_contents($stop, $bad.' '.$reads);
            exit(0);
        }
        for ($i = 0; $i < 50; $i++) {
            $this->writeFile('app/Thing.php', str_repeat('x', $i * 97));
            $this->snapshot();
        }
        touch($stop);
        pcntl_waitpid($pid, $status);
        [$bad, $reads] = array_map('intval', explode(' ', (string) file_get_contents($stop)));
        unlink($stop);

        $this->assertGreaterThan(0, $reads);
        $this->assertSame(0, $bad);
    }

    public function test_the_reader_tells_why_a_list_cannot_be_used(): void
    {
        try {
            Snapshot::reader($this->root);
            $this->fail('no list');
        } catch (SnapshotUnavailable $e) {
            $this->assertSame(Gap::NoSnapshot, $e->gap);
        }

        $this->snapshot();
        unlink(Snapshot::path());
        symlink($this->root.'/composer.json', Snapshot::path());
        try {
            Snapshot::reader($this->root);
            $this->fail('a link');
        } catch (SnapshotUnavailable $e) {
            $this->assertSame(Gap::BadSnapshot, $e->gap);
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_a_line_too_long_without_newline_is_broken_not_a_crash(): void
    {
        $this->snapshot();
        $head = fgets(fopen(Snapshot::path(), 'r'));
        $path = Snapshot::path();
        unlink($path);
        file_put_contents($path, $head);
        $handle = fopen($path, 'r+');
        ftruncate($handle, strlen($head) + 100 * 1024 * 1024);
        fclose($handle);

        $result = $this->inChild(function () {
            $report = $this->check();

            return $report->hasGap(Gap::BrokenSnapshot);
        }, 30, '128M');

        $this->assertSame(0, $result['exit'], (string) json_encode($result));
        $this->assertTrue($result['result']['result']);
    }

    private function sparse(string $relative, int $bytes): string
    {
        $path = $this->writeFile($relative, '');
        $handle = fopen($path, 'r+');
        ftruncate($handle, $bytes);
        fclose($handle);
        clearstatcache();
        $this->assertLessThan(1024 * 1024, stat($path)['blocks'] * 512, 'このファイルシステムは疎なファイルを持てない');

        return $path;
    }
}
