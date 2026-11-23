<?php

namespace Miraqceed\Foundation\Tests\Support\DataFiles;

use Illuminate\Support\Facades\File;
use Miraqceed\Foundation\DataFiles\Refresher;
use Miraqceed\Foundation\DataFiles\Fetcher;
use Miraqceed\Foundation\DataFiles\Store;

/**
 * 試しごとの置き場所とキャッシュの所（storage の下に乱数の名前。sys_get_temp_dir() の下にしない：
 * 「一時のファイルを sys_get_temp_dir() に作らない」の試しと紛れるため）。
 * 環境の変数 DATA_FILES_TEST_ROOT があれば、その下に作る（コンテナの中のディレクトリでも確かめるため。試しだけが読む）
 */
trait UsesDataFileDirectories
{
    protected string $dataRoot;

    /** @var list<LocalHttpServer> */
    private array $servers = [];

    protected function setUpDataFileDirectories(): void
    {
        $root = getenv('DATA_FILES_TEST_ROOT') ?: storage_path('framework/testing');
        $this->dataRoot = rtrim($root, '/').'/data-files-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dataRoot);
        config([
            'foundation.data_files.path' => $this->dataRoot.'/files',
            'foundation.data_files.cache_path' => $this->dataRoot.'/cache',
        ]);
    }

    protected function tearDownDataFileDirectories(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        if (isset($this->dataRoot)) {
            File::deleteDirectory($this->dataRoot);
        }
    }

    protected function server(int $workers = 4): LocalHttpServer
    {
        return $this->servers[] = new LocalHttpServer($workers);
    }

    /** 容器から作るので、試しごとの物をそのクラスに結び付ける（2つ目は SecondTestSource など、別のクラスにする） */
    protected function useSources(TestSource ...$sources): void
    {
        foreach ($sources as $source) {
            $this->app->instance($source::class, $source);
        }
        config(['foundation.data_files.sources' => array_map(fn (TestSource $source) => $source::class, $sources)]);
    }

    /** 全体の時間の上限を短くした Refresher を容器に置く */
    protected function useTimeout(int $seconds): void
    {
        $this->app->instance(Refresher::class, new Refresher(new Fetcher($seconds)));
    }

    protected function store(string $name = 'sample'): Store
    {
        return Store::for($name);
    }
}
