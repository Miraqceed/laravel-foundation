<?php

namespace Miraqceed\Foundation\Tests\Feature\DataFiles;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Miraqceed\Foundation\DataFiles\Download;
use Miraqceed\Foundation\DataFiles\Fetcher;
use Miraqceed\Foundation\DataFiles\Refresher;
use Miraqceed\Foundation\Tests\Support\DataFiles\LoopbackDownload;
use Miraqceed\Foundation\Tests\Support\DataFiles\TestSource;
use Miraqceed\Foundation\Tests\Support\DataFiles\UsesDataFileDirectories;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * 試しのための http の口（Download の private static loopbackForTests()）は、本番で開かない：
 * 公開の API にない・設定・環境の変数・アプリの環境・容器のどれからも開かない
 */
class LoopbackClosedTest extends TestCase
{
    use UsesDataFileDirectories;

    private const ENV_NAMES = ['FOUNDATION_DATA_FILES_ALLOW_HTTP', 'FOUNDATION_DATA_FILES_LOOPBACK', 'DATA_FILES_ALLOW_HTTP'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDataFileDirectories();
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_NAMES as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->tearDownDataFileDirectories();
        parent::tearDown();
    }

    /** @return list<string> そのクラスで宣言した public なメソッドの名前 */
    private static function publicMethods(string $class): array
    {
        $names = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            array_filter((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $method) => $method->class === $class),
        );
        sort($names);

        return $names;
    }

    public function test_the_test_hatch_is_not_in_the_public_api(): void
    {
        $this->assertSame(
            ['followRedirects', 'followsRedirects', 'gzip', 'headers', 'isGzip', 'notFoundMeansNotYet', 'permits', 'redact', 'redactedUrl', 'target', 'treatsNotFoundAsNotYet', 'url', 'withHeaders'],
            self::publicMethods(Download::class),
        );
        $this->assertSame(['__construct', 'fetch'], self::publicMethods(Fetcher::class));
        $this->assertTrue((new ReflectionClass(Download::class))->isFinal());
        $this->assertTrue((new ReflectionClass(Fetcher::class))->isFinal());

        $hatch = new ReflectionMethod(Download::class, 'loopbackForTests');
        $this->assertTrue($hatch->isPrivate());
        $this->assertTrue($hatch->isStatic());
        // http を通すかの印は、作るときだけ決まる
        $flag = (new ReflectionClass(Download::class))->getProperty('loopback');
        $this->assertTrue($flag->isPrivate());
        $this->assertTrue($flag->isReadOnly());
    }

    /** @return array<string, array{0: string}> */
    public static function environments(): array
    {
        return ['production' => ['production'], 'local' => ['local'], 'testing' => ['testing']];
    }

    #[DataProvider('environments')]
    public function test_no_setting_environment_or_binding_opens_http(string $environment): void
    {
        $http = $this->server(1);
        $http->route('/sample.txt', ['body' => TestSource::data(1)]);

        $this->app['env'] = $environment;
        config([
            'foundation.data_files.allow_http' => true,
            'foundation.data_files.loopback' => true,
            'foundation.data_files.allow_loopback' => true,
            'foundation.data_files.testing' => true,
        ]);
        foreach (self::ENV_NAMES as $name) {
            putenv("{$name}=true");
            $_ENV[$name] = $_SERVER[$name] = 'true';
        }
        // 容器の結び付けを替えても（Fetcher・Refresher）
        $this->app->instance(Fetcher::class, new Fetcher(5));
        $this->app->instance(Refresher::class, new Refresher(new Fetcher(5)));

        try {
            Download::url($http->url('/sample.txt'));
            $this->fail('http を通した');
        } catch (InvalidArgumentException) {
            // 断った
        }

        $source = new TestSource;
        $source->downloads = fn () => [Download::url($http->url('/sample.txt'))];
        $this->useSources($source);

        $this->assertSame(1, Artisan::call('foundation:data-files'));
        $this->assertSame([], $http->requests(), '手元の口に届いた');
        $this->assertFalse($this->store()->hasData());
    }

    /** @return array<string, array{0: string}> */
    public static function nonLoopbackUrls(): array
    {
        return [
            'localhost' => ['http://localhost:8080/x'],
            '127.0.0.2' => ['http://127.0.0.2:8080/x'],
            'example.com' => ['http://example.com:8080/x'],
            '番号なし' => ['http://127.0.0.1/x'],
            'userinfo つき' => ['http://user:pass@127.0.0.1:8080/x'],
            'https' => ['https://127.0.0.1:8080/x'],
        ];
    }

    #[DataProvider('nonLoopbackUrls')]
    public function test_the_hatch_itself_passes_only_numbered_loopback_http(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        LoopbackDownload::url($url);
    }

    public function test_the_hatch_passes_ipv4_and_ipv6_loopback(): void
    {
        $this->assertSame('http://127.0.0.1:8080/x', LoopbackDownload::url('http://127.0.0.1:8080/x')->target());
        $this->assertSame('http://[::1]:8080/x', LoopbackDownload::url('http://[::1]:8080/x')->target());
        // 同じ元の中だけを許す
        $download = LoopbackDownload::url('http://127.0.0.1:8080/x');
        $this->assertTrue($download->permits('http://127.0.0.1:8080/y'));
        $this->assertFalse($download->permits('http://127.0.0.1:8081/y'));
        $this->assertFalse($download->permits('http://[::1]:8080/y'));
        $this->assertFalse($download->permits('https://data.example.com/y'));
    }

    public function test_the_test_helpers_stay_out_of_the_distribution(): void
    {
        $attributes = (string) file_get_contents(__DIR__.'/../../../.gitattributes');

        $this->assertMatchesRegularExpression('#^/tests\s+export-ignore\s*$#m', $attributes);
    }
}
