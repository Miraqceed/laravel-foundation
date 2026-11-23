<?php

namespace Miraqceed\Foundation\DataFiles;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Throwable;

/**
 * foundation.data_files.sources に並べた種類を、容器から作る（呼んだときだけ。プロバイダーの起動では作らない）。
 * 書き違い（DataSource でない・名前の形が違う・同じ名前が2つ）は LogicException（文に設定の値を入れない）
 *
 * @internal
 */
final class Sources
{
    public const NAME_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /** @var array<string, DataSource|null> find() が作った種類（作れなかったものは null） */
    private array $made = [];

    public function __construct(private readonly Container $container) {}

    /**
     * 読む側のための引き方：並べた順に、まだ作っていない種類だけを作り（作った物はこの入れ物で覚える）、名前の合う種類を返す。
     * ほかの種類が壊れていても（クラスがない・DataSource でない・作ると投げる）、名前の合う種類は返す。
     * 書き違いの点検は、命令と deploy-check の all() が行う
     *
     * @throws LogicException 名前の合う種類がないとき
     */
    public function find(string $name): DataSource
    {
        $configured = config('foundation.data_files.sources', []);
        foreach (is_array($configured) ? array_values($configured) : [] as $class) {
            if (! is_string($class)) {
                continue;
            }
            if (! array_key_exists($class, $this->made)) {
                try {
                    $made = class_exists($class) ? $this->container->make($class) : null;
                    $this->made[$class] = $made instanceof DataSource ? $made : null;
                } catch (Throwable) {
                    $this->made[$class] = null;
                }
            }
            $source = $this->made[$class];
            try {
                if ($source !== null && $source->name() === $name) {
                    return $source;
                }
            } catch (Throwable) {
                // 名前を返せない種類は飛ばす
            }
        }

        throw new LogicException('データのファイルの種類が foundation.data_files.sources にありません');
    }

    /**
     * @return array<string, DataSource> 名前 → 種類（設定の順）
     *
     * @throws LogicException
     */
    public function all(): array
    {
        $configured = config('foundation.data_files.sources', []);
        if (! is_array($configured)) {
            throw new LogicException('foundation.data_files.sources は、DataSource のクラス名の配列にしてください');
        }

        $sources = [];
        foreach (array_values($configured) as $i => $class) {
            $source = is_string($class) && class_exists($class) ? $this->container->make($class) : null;
            if (! $source instanceof DataSource) {
                throw new LogicException('foundation.data_files.sources の '.($i + 1).' 番目が、DataSource を実装したクラスではありません');
            }
            $name = $source->name();
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new LogicException('foundation.data_files.sources の '.($i + 1).' 番目の名前は、1〜64字の ASCII の英数字・-・_ にしてください');
            }
            if (isset($sources[$name])) {
                throw new LogicException('foundation.data_files.sources に、同じ名前の種類が2つあります（'.($i + 1).' 番目）');
            }
            $sources[$name] = $source;
        }

        return $sources;
    }

    /** @throws LogicException 登録していない名前のとき */
    public function get(string $name): DataSource
    {
        $sources = $this->all();
        if (! isset($sources[$name])) {
            throw new LogicException('データのファイルの種類が foundation.data_files.sources にありません');
        }

        return $sources[$name];
    }
}
