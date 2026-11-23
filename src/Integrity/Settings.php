<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 見る場所を決める設定。一覧の頭の行に、作ったときの値を持つ。
 *
 * - 一覧が使えないとき：今の値で見る
 * - 一覧が使えるとき：一覧の値と今の値の厳しい方。外す・許すもの（exclude・acme・served を調べ1から外すこと、public_php・
 *   storage_php_allow・maintenance_allow・generated_views・自動で外す SQLite のファイル）は両方にあるものだけ、
 *   見る場所（acme・served・links・view_compiled）は両方を合わせる
 * - 違えば「確かめられなかった（設定が一覧と違う）」
 *
 * @internal
 */
final class Settings
{
    /** 調べと画面の調べの子が読み込む場所。exclude・served・acme がこれに掛かれば知らせる。SQLite のファイルも、この中なら外さない */
    public const LOAD_PATHS = ['artisan', 'bootstrap', 'config', 'app', 'routes', 'vendor', '.env'];

    /** SQLite が本体のファイルの横に作るファイル */
    public const SQLITE_SUFFIXES = ['', '-wal', '-shm', '-journal'];

    /** generated_views で、Blade::render()・インラインの部品が置く元を許す書き方（画面のフォルダーの道の後ろに付ける） */
    public const INLINE_VIEWS = '/*.blade.php';

    /**
     * @param  list<string>  $exclude  根からの道
     * @param  list<string>  $served  根からの道
     * @param  list<string>  $acme  根からの道（ふつうは1つ）
     * @param  list<string>  $publicPhp  根からの道
     * @param  list<string>  $storagePhpAllow  storage からの道
     * @param  list<string>  $maintenanceAllow  down の項目の名前
     * @param  list<string>  $links  filesystems.links の先（根からの道。根の外は outside:sha256）
     * @param  list<string>  $viewCompiled  画面のフォルダー（根からの道。根の外は outside:sha256）
     * @param  list<string>  $generatedViews  部品が作る画面の場所（根からの道。画面のフォルダーの下のフォルダーか、画面のフォルダー + /*.blade.php）
     * @param  list<string>  $sqlite  自動で外す、木の中の SQLite のファイル（根からの道。ふつうのファイルのときだけ外す）
     * @param  array{generated_views: list<string>, sqlite: list<string>, exclude: list<string>}  $refused  今の設定のうち、使えないもの（一覧に入れない）
     */
    public function __construct(
        public readonly array $exclude,
        public readonly array $served,
        public readonly array $acme,
        public readonly array $publicPhp,
        public readonly array $storagePhpAllow,
        public readonly array $maintenanceAllow,
        public readonly array $links,
        public readonly array $viewCompiled,
        public readonly array $generatedViews = [],
        public readonly array $sqlite = [],
        public readonly array $refused = ['generated_views' => [], 'sqlite' => [], 'exclude' => []],
    ) {}

    public static function current(string $root): self
    {
        $config = (array) config('foundation.integrity', []);
        // null か '' なら見ない（?? は null を「ない」と同じに扱うので、キーがあるかで分ける）
        $acme = array_key_exists('acme', $config) ? $config['acme'] : 'public/.well-known/acme-challenge';

        $links = [];
        foreach (array_values((array) config('filesystems.links', [])) as $target) {
            $links[] = self::relative($root, (string) $target);
        }
        $compiled = (string) config('view.compiled', '');
        $viewCompiled = $compiled === '' ? [] : [self::relative($root, $compiled)];
        [$generated, $refusedGenerated] = self::generated(self::paths($config['generated_views'] ?? []), $viewCompiled);

        $served = self::paths($config['served'] ?? []);
        $acmePaths = $acme === null || $acme === '' ? [] : self::paths([$acme]);
        $publicPhp = self::paths($config['public_php'] ?? ['public/index.php']);
        // 公開される場所：public・served・acme・木の中の filesystems.links の先
        $publicPlaces = ['public', ...$served, ...$acmePaths, ...array_filter($links, fn (string $path): bool => $path !== '' && ! str_starts_with($path, 'outside:'))];
        [$sqlite, $refusedSqlite] = self::sqliteFiles($root, $publicPlaces);
        $exclude = self::paths($config['exclude'] ?? []);
        $refusedExclude = self::refusedExclude($exclude, $publicPhp, $publicPlaces);

        return new self(
            exclude: array_values(array_diff($exclude, $refusedExclude)),
            served: $served,
            acme: $acmePaths,
            publicPhp: $publicPhp,
            storagePhpAllow: self::paths($config['storage_php_allow'] ?? []),
            maintenanceAllow: self::names($config['maintenance']['allow'] ?? []),
            links: self::sorted($links),
            viewCompiled: $viewCompiled,
            generatedViews: $generated,
            sqlite: $sqlite,
            refused: ['generated_views' => $refusedGenerated, 'sqlite' => $refusedSqlite, 'exclude' => self::sorted($refusedExclude)],
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $lists = [];
        foreach (['exclude', 'served', 'acme', 'public_php', 'storage_php_allow', 'maintenance_allow', 'links', 'view_compiled', 'generated_views', 'sqlite'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key]) || array_filter($data[$key], fn ($v) => ! is_string($v)) !== []) {
                return null;
            }
            $lists[$key] = self::sorted(array_values($data[$key]));
        }

        return new self($lists['exclude'], $lists['served'], $lists['acme'], $lists['public_php'], $lists['storage_php_allow'],
            $lists['maintenance_allow'], $lists['links'], $lists['view_compiled'], $lists['generated_views'], $lists['sqlite']);
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return [
            'exclude' => $this->exclude,
            'served' => $this->served,
            'acme' => $this->acme,
            'public_php' => $this->publicPhp,
            'storage_php_allow' => $this->storagePhpAllow,
            'maintenance_allow' => $this->maintenanceAllow,
            'links' => $this->links,
            'view_compiled' => $this->viewCompiled,
            'generated_views' => $this->generatedViews,
            'sqlite' => $this->sqlite,
        ];
    }

    public function sameAs(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /** 一覧の値と今の値の厳しい方 */
    public function strictest(self $snapshot): self
    {
        $both = fn (array $a, array $b): array => self::sorted(array_values(array_intersect($a, $b)));
        $either = fn (array $a, array $b): array => self::sorted(array_values(array_unique([...$a, ...$b])));

        return new self(
            exclude: $both($this->exclude, $snapshot->exclude),
            served: $either($this->served, $snapshot->served),
            acme: $either($this->acme, $snapshot->acme),
            publicPhp: $both($this->publicPhp, $snapshot->publicPhp),
            storagePhpAllow: $both($this->storagePhpAllow, $snapshot->storagePhpAllow),
            maintenanceAllow: $both($this->maintenanceAllow, $snapshot->maintenanceAllow),
            links: $either($this->links, $snapshot->links),
            viewCompiled: $either($this->viewCompiled, $snapshot->viewCompiled),
            generatedViews: $both($this->generatedViews, $snapshot->generatedViews),
            sqlite: $both($this->sqlite, $snapshot->sqlite),
            refused: $this->refused,
        );
    }

    /**
     * 調べ1から外す道（acme・served も、両方の値にあるときだけ外す。見る場所としては合わせた方を 4 で見る）
     *
     * @return list<string>
     */
    public function excludedFromTree(?self $snapshot): array
    {
        $own = [...$this->exclude, ...$this->served, ...$this->acme];
        if ($snapshot === null) {
            return self::sorted(array_values(array_unique($own)));
        }
        $theirs = [...$snapshot->exclude, ...$snapshot->served, ...$snapshot->acme];

        return self::sorted(array_values(array_intersect(array_unique($own), array_unique($theirs))));
    }

    /**
     * 調べ1から外す SQLite のファイル（両方の値にあるときだけ。外すのは、ふつうのファイルのときだけ）
     *
     * @return list<string>
     */
    public function sqliteExcluded(?self $snapshot): array
    {
        return $snapshot === null ? $this->sqlite : self::sorted(array_values(array_intersect($this->sqlite, $snapshot->sqlite)));
    }

    /**
     * exclude・served・acme のうち、調べと子の読み込む場所に掛かるもの（その道か、その上か下）
     *
     * @return list<string>
     */
    public function coveringLoadPaths(): array
    {
        $hits = [];
        foreach ([...$this->exclude, ...$this->served, ...$this->acme] as $path) {
            if (self::coversLoadPath($path)) {
                $hits[] = $path;
            }
        }

        return self::sorted(array_values(array_unique($hits)));
    }

    /** 道が、調べと子の読み込む場所に掛かるか（その道か、その上か下） */
    public static function coversLoadPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        foreach (self::LOAD_PATHS as $load) {
            if ($path === $load || str_starts_with($path.'/', $load.'/') || str_starts_with($load.'/', $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /** 道が、外す道のどれかの中か（道の頭で、/ の区切りで合わせる） */
    public static function within(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * generated_views のうち、下のフォルダーの形（根からの道）
     *
     * @return list<string>
     */
    public function generatedFolders(): array
    {
        return array_values(array_filter($this->generatedViews, fn (string $path): bool => ! str_ends_with($path, self::INLINE_VIEWS)));
    }

    /**
     * generated_views で、Blade::render() の元を許した画面のフォルダー（根からの道）
     *
     * @return list<string>
     */
    public function inlineViewDirs(): array
    {
        $dirs = [];
        foreach ($this->generatedViews as $path) {
            if (str_ends_with($path, self::INLINE_VIEWS)) {
                $dirs[] = substr($path, 0, -strlen(self::INLINE_VIEWS));
            }
        }

        return $dirs;
    }

    /** 根からの道。根の外なら outside:sha256（一覧に絶対の道を入れない） */
    public static function relative(string $root, string $path): string
    {
        // リンクそのものの道（辿った先でなく）。親のフォルダーだけを解く
        $real = is_link($path) ? (($parent = realpath(dirname($path))) === false ? false : $parent.'/'.basename($path)) : realpath($path);
        $normalized = rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($normalized === $root) {
            return '';
        }
        if (str_starts_with($normalized, $root.'/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return 'outside:'.hash('sha256', $normalized);
    }

    /**
     * generated_views を、許せるもの（木の中の画面のフォルダーの下のフォルダー・画面のフォルダー + /*.blade.php）と、
     * 許せないもの（画面のフォルダーそのもの・その外・. や .. を含む道・ほかの * の形）に分ける
     *
     * @param  list<string>  $paths
     * @param  list<string>  $viewCompiled
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function generated(array $paths, array $viewCompiled): array
    {
        $compiled = $viewCompiled[0] ?? '';
        $usable = $compiled !== '' && ! str_starts_with($compiled, 'outside:');
        $allowed = [];
        $refused = [];
        foreach ($paths as $path) {
            $segments = explode('/', $path);
            $plain = ! in_array('.', $segments, true) && ! in_array('..', $segments, true) && ! in_array('', $segments, true);
            if ($usable && $plain && ($path === $compiled.self::INLINE_VIEWS
                || (str_starts_with($path, $compiled.'/') && ! str_contains($path, '*')))) {
                $allowed[] = $path;
            } else {
                $refused[] = $path;
            }
        }

        return [self::sorted($allowed), self::sorted($refused)];
    }

    /**
     * sqlite の接続が指す、木の中のファイル（本体と -wal・-shm・-journal）。PHP として動く名前・調べが読み込む場所の中のものは外さない
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function sqliteFiles(string $root, array $publicPlaces): array
    {
        $files = [];
        $refused = [];
        foreach ((array) config('database.connections', []) as $connection) {
            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'sqlite') {
                continue;
            }
            $database = (string) ($connection['database'] ?? '');
            if ($database === '' || $database === ':memory:' || str_starts_with($database, 'file:') || str_contains($database, 'mode=memory')) {
                continue;
            }
            // Laravel は、そのままの道か、アプリの根からの道で開く。雛形の設定は sqlite の接続も DB_DATABASE を読むので、
            // MySQL のデータベースの名前（laravel・app など）が根からの道に見える。根からの道は、ファイルがあるときだけ見る
            if (! str_starts_with(str_replace('\\', '/', $database), '/') && preg_match('/\A[A-Za-z]:/', $database) !== 1) {
                $database = rtrim($root, '/').'/'.$database;
                if (! is_file($database)) {
                    continue;
                }
            }
            $base = self::relative($root, $database);
            if ($base === '' || str_starts_with($base, 'outside:')) {
                continue;
            }
            foreach (self::SQLITE_SUFFIXES as $suffix) {
                $path = $base.$suffix;
                // 公開される場所（public・served・acme・filesystems.links の先）の下は外さない（そこに置くこと自体が危ない）
                if (Context::isPhpName(basename($path)) || self::coversLoadPath($path) || self::within($path, $publicPlaces)) {
                    $refused[] = $path;
                } else {
                    $files[] = $path;
                }
            }
        }

        return [self::sorted(array_values(array_unique($files))), self::sorted(array_values(array_unique($refused)))];
    }

    /** SQLite の頭の印。本体のファイルの初めの 16 バイト */
    public const SQLITE_HEADER = "SQLite format 3\0";

    /**
     * 外す SQLite のファイルが、本当に SQLite のものか。本体（横のファイルなら、その本体）が SQLite の頭の印を持つか
     */
    public static function isSqliteFile(string $root, string $path): bool
    {
        $base = $path;
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (str_ends_with($path, $suffix)) {
                $base = substr($path, 0, -strlen($suffix));
                break;
            }
        }
        try {
            $handle = @fopen(rtrim($root, '/').'/'.$base, 'rb');
            if ($handle === false) {
                return false;
            }
            $stat = @fstat($handle);
            $head = $stat !== false && ($stat['mode'] & 0170000) === 0100000 ? (string) @fread($handle, 16) : '';
            fclose($handle);

            return $head === self::SQLITE_HEADER;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * exclude のうち、公開される場所（public・served・acme・filesystems.links の先）の下で、PHP として動く名前を持つもの・
     * public_php に掛かるもの（使わない。public/index.php の書き換えを黙らせないように）
     *
     * @param  list<string>  $exclude
     * @param  list<string>  $publicPhp
     * @param  list<string>  $publicPlaces
     * @return list<string>
     */
    private static function refusedExclude(array $exclude, array $publicPhp, array $publicPlaces): array
    {
        $refused = [];
        foreach ($exclude as $path) {
            if (! self::within($path, $publicPlaces) && ! array_filter($publicPlaces, fn (string $place): bool => self::within($place, [$path]))) {
                continue;
            }
            $coversPublicPhp = array_filter($publicPhp, fn (string $php): bool => self::within($php, [$path])) !== [];
            if (Context::isPhpName(basename($path)) || $coversPublicPhp) {
                $refused[] = $path;
            }
        }

        return $refused;
    }

    /** @return list<string> */
    private static function paths(mixed $values): array
    {
        $paths = [];
        foreach ((array) $values as $value) {
            $value = trim(str_replace('\\', '/', (string) $value), '/');
            if ($value !== '') {
                $paths[] = $value;
            }
        }

        return self::sorted(array_values(array_unique($paths)));
    }

    /** @return list<string> */
    private static function names(mixed $values): array
    {
        return self::sorted(array_values(array_unique(array_map('strval', (array) $values))));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return array_values($values);
    }
}
