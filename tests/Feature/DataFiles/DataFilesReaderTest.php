<?php

namespace Miraqceed\Foundation\Tests\Feature\DataFiles;

use ArrayObject;
use Illuminate\Support\Facades\File;
use LogicException;
use Miraqceed\Foundation\DataFiles\DataFiles;
use Miraqceed\Foundation\DataFiles\Sources;
use Miraqceed\Foundation\DataFiles\Store;
use Miraqceed\Foundation\Testing\ReportFailure;
use Miraqceed\Foundation\Tests\Support\DataFiles\SecondTestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\TestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\ThrowingSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\UsesDataFileDirectories;
use Miraqceed\Foundation\Tests\TestCase;
use stdClass;

/**
 * 読む側の助け：道（写しへ戻る・ないときに間引いて知らせる）・変わったかの鍵（inode も）・解いた結果の写し（allowed_classes）
 */
class DataFilesReaderTest extends TestCase
{
    use UsesDataFileDirectories;

    private TestSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDataFileDirectories();
        $this->source = new TestSource;
        $this->useSources($this->source);
    }

    protected function tearDown(): void
    {
        $this->tearDownDataFileDirectories();
        parent::tearDown();
    }

    /** 置き場所に、一時のファイル + rename で入れる（命令と同じ入れ方） */
    private function place(string $contents, ?int $mtime = null, string $name = 'sample'): void
    {
        $store = $this->store($name);
        $store->lock();
        $temporary = $store->temporaryPath();
        file_put_contents($temporary, $contents);
        if ($mtime !== null) {
            touch($temporary, $mtime);
        }
        $store->install($temporary, $name);
        $store->unlock();
    }

    private function files(): DataFiles
    {
        return app(DataFiles::class);
    }

    public function test_path_reads_the_data_then_the_fallback_then_nothing(): void
    {
        $this->assertNull($this->files()->path('sample'));

        $fallback = $this->dataRoot.'/bundled.txt';
        file_put_contents($fallback, TestSource::data(1));
        $this->source->fallback = $fallback;
        $this->assertSame($fallback, $this->files()->path('sample'));

        $this->place(TestSource::data(2));
        $this->assertSame($this->store()->dataPath(), $this->files()->path('sample'));
    }

    /** 頼まれた種類だけを引く：ほかの種類が壊れていても止まらず、作った種類は覚えて作り直さない */
    public function test_path_finds_its_kind_even_when_another_kind_is_broken(): void
    {
        config(['foundation.data_files.sources' => ['Not\\A\\Class', ThrowingSource::class, TestSource::class]]);
        $made = ThrowingSource::$made;
        $this->place(TestSource::data(1));

        $this->assertSame($this->store()->dataPath(), $this->files()->path('sample'));
        $this->assertSame($this->store()->dataPath(), $this->files()->path('sample'));
        $this->assertNotNull($this->files()->changeKey('sample'));

        // 作ると投げる種類も、作ろうとしたのは1回だけ
        $this->assertSame($made + 1, ThrowingSource::$made);
    }

    public function test_an_unknown_name_is_a_mistake(): void
    {
        $this->expectException(LogicException::class);
        $this->files()->path('unknown');
    }

    public function test_reports_missing_data_once_an_hour_across_processes_and_stays_quiet_locally(): void
    {
        $this->app['env'] = 'production';
        $reports = ReportFailure::simulate();

        $this->assertNull($this->files()->path('sample'));
        $this->assertNull($this->files()->path('sample'));
        // 別の入れ物（別のプロセスと同じく、目印のファイルで間引く）
        $this->assertNull((new DataFiles(app(Sources::class)))->path('sample'));
        $this->assertCount(1, $reports);
        $this->assertStringContainsString('データのファイル sample がありません', $reports[0]);

        // 写しに戻ったときも知らせる（別の鍵ではないので、同じ窓の中では間引く）
        $this->travel(DataFiles::MISSING_REPORT_SECONDS + 1)->seconds();
        $fallback = $this->dataRoot.'/bundled.txt';
        file_put_contents($fallback, TestSource::data(1));
        $this->source->fallback = $fallback;
        $this->assertSame($fallback, $this->files()->path('sample'));
        $this->assertCount(2, $reports);
        $this->assertStringContainsString('写しを読んでいます', $reports[1]);

        foreach (['local', 'testing'] as $environment) {
            $this->travel(DataFiles::MISSING_REPORT_SECONDS + 1)->seconds();
            $this->app['env'] = $environment;
            $this->files()->path('sample');
            $this->assertCount(2, $reports, $environment);
        }
    }

    public function test_the_change_key_moves_even_when_time_and_size_are_kept(): void
    {
        $this->place(TestSource::data(1), 1_000_000_000);
        $first = $this->files()->changeKey('sample');
        $this->assertSame($first, $this->files()->changeKey('sample'));

        // 同じ大きさ・同じ時刻の別の中身（rsync -t の形）
        $other = str_replace('row-1-', 'row-9-', TestSource::data(1));
        $this->assertSame(strlen(TestSource::data(1)), strlen($other));
        $this->place($other, 1_000_000_000);

        $this->assertNotSame($first, $this->files()->changeKey('sample'));
    }

    public function test_remember_parses_once_per_key_and_keeps_it_across_processes(): void
    {
        $this->place(TestSource::data(1));
        $parsed = new ArrayObject;
        $parse = function (string $path) use ($parsed): array {
            $parsed[] = $path;

            return ['version' => TestSource::versionOf((string) file_get_contents($path))];
        };

        $this->assertSame(['version' => 1], $this->files()->remember('sample', $parse, allowedClasses: []));
        $this->assertSame(['version' => 1], $this->files()->remember('sample', $parse, allowedClasses: []));
        $this->assertCount(1, $parsed);

        // 別のプロセス（新しい入れ物）：置いた写しから読む
        $fresh = new DataFiles(app(Sources::class));
        $this->assertSame(['version' => 1], $fresh->remember('sample', $parse, allowedClasses: []));
        $this->assertCount(1, $parsed);

        // 入れ替えれば解き直し、古い写しは消える
        $this->place(TestSource::data(2));
        $this->assertSame(['version' => 2], $this->files()->remember('sample', $parse, allowedClasses: []));
        $this->assertCount(2, $parsed);
        $this->assertCount(1, glob(DataFiles::cachePath().'/sample-*.ser'));
    }

    public function test_remember_drops_a_damaged_copy_or_one_with_classes_it_does_not_allow(): void
    {
        $this->place(TestSource::data(1));
        $parse = fn (string $path) => new ArrayObject(['parsed']);
        $this->files()->remember('sample', $parse, allowedClasses: [ArrayObject::class]);
        [$kept] = glob(DataFiles::cachePath().'/sample-*.ser');

        foreach ([
            '壊れた写し' => 'not serialized',
            '許していない物' => serialize(new ArrayObject([new stdClass])),
        ] as $label => $text) {
            file_put_contents($kept, $text);
            $fresh = new DataFiles(app(Sources::class));
            $calls = 0;
            $value = $fresh->remember('sample', function () use (&$calls) {
                $calls++;

                return new ArrayObject(['again']);
            }, allowedClasses: [ArrayObject::class]);
            $this->assertSame(1, $calls, $label);
            $this->assertSame(['again'], $value->getArrayCopy(), $label);
        }
    }

    public function test_remember_returns_the_value_when_it_cannot_keep_it(): void
    {
        $this->place(TestSource::data(1));
        // キャッシュの所をファイルにして、ディレクトリを作れなくする
        File::ensureDirectoryExists(dirname(DataFiles::cachePath()));
        file_put_contents(DataFiles::cachePath(), 'a file');

        $this->assertSame(['ok'], $this->files()->remember('sample', fn () => ['ok'], allowedClasses: []));
    }

    public function test_remember_removes_only_old_leftovers(): void
    {
        $this->place(TestSource::data(1));
        File::ensureDirectoryExists(DataFiles::cachePath());
        $old = DataFiles::cachePath().'/sample-'.str_repeat('a', 64).'.ser.'.str_repeat('1', 12).'.tmp';
        $fresh = DataFiles::cachePath().'/sample-'.str_repeat('b', 64).'.ser.'.str_repeat('2', 12).'.tmp';
        // 名前の頭が重なるほかの種類の、古い残り：消さない（その種類が自分で片付ける）
        $otherKind = DataFiles::cachePath().'/sample-two-'.str_repeat('c', 64).'.ser.'.str_repeat('3', 12).'.tmp';
        foreach ([$old, $fresh, $otherKind] as $file) {
            file_put_contents($file, 'x');
        }
        touch($old, time() - Store::LEFTOVER_SECONDS - 5);
        touch($otherKind, time() - Store::LEFTOVER_SECONDS - 5);

        $this->files()->remember('sample', fn () => ['ok'], allowedClasses: []);

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($otherKind);
    }

    /** 名前の頭が重なる種類（sample と sample-two）の写しを、互いに消さない（設計書 6.5：消すのは同じ名前の古い鍵の写しだけ） */
    public function test_remember_never_removes_the_copy_of_a_kind_whose_name_starts_the_same(): void
    {
        $second = new SecondTestSource('sample-two');
        $this->useSources($this->source, $second);
        $this->place(TestSource::data(1));
        $this->place(TestSource::data(2), name: 'sample-two');
        $parsed = new ArrayObject;
        $parse = function (string $path) use ($parsed): int {
            $parsed[] = basename(dirname($path));

            return TestSource::versionOf((string) file_get_contents($path));
        };

        // 別々の入れ物（別々のプロセスと同じ）で、sample-two → sample の順に解いて置く
        $this->assertSame(2, $this->freshFiles()->remember('sample-two', $parse, allowedClasses: []));
        $this->assertSame(1, $this->freshFiles()->remember('sample', $parse, allowedClasses: []));
        $this->assertCount(2, glob(DataFiles::cachePath().'/*.ser'));

        // 新しい入れ物は、どちらも写しから読む（解き直さない）
        $this->assertSame(2, $this->freshFiles()->remember('sample-two', $parse, allowedClasses: []));
        $this->assertSame(1, $this->freshFiles()->remember('sample', $parse, allowedClasses: []));
        $this->assertSame(['sample-two', 'sample'], $parsed->getArrayCopy());
    }

    private function freshFiles(): DataFiles
    {
        return new DataFiles(app(Sources::class));
    }

    public function test_remember_needs_the_allowed_classes(): void
    {
        $parameter = (new \ReflectionMethod(DataFiles::class, 'remember'))->getParameters()[2];

        $this->assertSame('allowedClasses', $parameter->getName());
        $this->assertFalse($parameter->isOptional());
    }
}
