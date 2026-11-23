<?php

namespace Miraqceed\Foundation\Integrity;

/**
 * 見る場所を決める設定。一覧の頭の行に、作ったときの値を持つ。
 *
 * - 一覧が使えないとき：今の値で見る
 * - 一覧が使えるとき：一覧の値と今の値の厳しい方。外す・許すもの（exclude・acme・served を調べ1から外すこと、public_php・
 *   storage_php_allow・maintenance_allow）は両方にあるものだけ、見る場所（acme・served・links・view_compiled）は両方を合わせる
 * - 違えば「確かめられなかった（設定が一覧と違う）」
 *
 * @internal
 */
final class Settings
{
    /** 調べと画面の調べの子が読み込む場所。exclude・served・acme がこれに掛かれば知らせる */
    public const LOAD_PATHS = ['artisan', 'bootstrap', 'config', 'app', 'routes', 'vendor', '.env'];

    /**
     * @param  list<string>  $exclude  根からの道
     * @param  list<string>  $served  根からの道
     * @param  list<string>  $acme  根からの道（ふつうは1つ）
     * @param  list<string>  $publicPhp  根からの道
     * @param  list<string>  $storagePhpAllow  storage からの道
     * @param  list<string>  $maintenanceAllow  down の項目の名前
     * @param  list<string>  $links  filesystems.links の先（根からの道。根の外は outside:sha256）
     * @param  list<string>  $viewCompiled  画面のフォルダー（根からの道。根の外は outside:sha256）
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
    ) {}

    public static function current(string $root): self
    {
        $config = (array) config('foundation.integrity', []);
        $acme = $config['acme'] ?? 'public/.well-known/acme-challenge';

        $links = [];
        foreach (array_values((array) config('filesystems.links', [])) as $target) {
            $links[] = self::relative($root, (string) $target);
        }
        $compiled = (string) config('view.compiled', '');

        return new self(
            exclude: self::paths($config['exclude'] ?? []),
            served: self::paths($config['served'] ?? []),
            acme: $acme === null || $acme === '' ? [] : self::paths([$acme]),
            publicPhp: self::paths($config['public_php'] ?? ['public/index.php']),
            storagePhpAllow: self::paths($config['storage_php_allow'] ?? []),
            maintenanceAllow: self::names($config['maintenance']['allow'] ?? []),
            links: self::sorted($links),
            viewCompiled: $compiled === '' ? [] : [self::relative($root, $compiled)],
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $lists = [];
        foreach (['exclude', 'served', 'acme', 'public_php', 'storage_php_allow', 'maintenance_allow', 'links', 'view_compiled'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key]) || array_filter($data[$key], fn ($v) => ! is_string($v)) !== []) {
                return null;
            }
            $lists[$key] = self::sorted(array_values($data[$key]));
        }

        return new self($lists['exclude'], $lists['served'], $lists['acme'], $lists['public_php'], $lists['storage_php_allow'], $lists['maintenance_allow'], $lists['links'], $lists['view_compiled']);
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
     * exclude・served・acme のうち、調べと子の読み込む場所に掛かるもの（その道か、その上か下）
     *
     * @return list<string>
     */
    public function coveringLoadPaths(): array
    {
        $hits = [];
        foreach ([...$this->exclude, ...$this->served, ...$this->acme] as $path) {
            foreach (self::LOAD_PATHS as $load) {
                if ($path === $load || str_starts_with($path.'/', $load.'/') || str_starts_with($load.'/', $path.'/') || $path === '') {
                    $hits[] = $path;
                    break;
                }
            }
        }

        return self::sorted(array_values(array_unique($hits)));
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
