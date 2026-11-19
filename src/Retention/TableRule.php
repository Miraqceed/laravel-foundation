<?php

namespace Miraqceed\Foundation\Retention;

use InvalidArgumentException;
use LogicException;

/**
 * 台帳の1つの表の消し方。MapBuilder::table() が返す。
 * 1つの表に、期限（prunedBy・prunedByCommand）と持ち主の消去（erasedWith・anonymizedWith）の両方を書ける。
 * kept（消さない）と handledByApp（アプリが自分で消す）は、持ち主の消去と一緒に書けない
 */
final class TableRule
{
    /**
     * 持ち主の消去で消す行の見つけ方
     *
     * @var list<array{owner: string, column: ?string, via: ?array{table: string, column: string, ownerColumn: string, key: string}, morph: ?string}>
     */
    private array $erasures = [];

    /** @var ?array{owner: string, key: string, erase: array<string, mixed>, keep: list<string>, reason: string} */
    private ?array $anonymize = null;

    /** @var list<class-string> */
    private array $models = [];

    /** @var list<string> */
    private array $commands = [];

    private ?string $keptReason = null;

    private ?string $handledBy = null;

    /** パッケージの表のとき、その部品を有効にする設定の鍵（有効でなく、表もなければ数えない） */
    private ?string $component = null;

    public function __construct(public readonly string $name) {}

    /**
     * 持ち主の消去で、この表の行を消す。持ち主の見つけ方は次のどれか1つ：
     * - column：持ち主の鍵を持つ列（posts.user_id）
     * - via：1段の経由 [親の表, この表の親への列, 親の表の持ち主の列, 親の鍵（既定 id）]（post_images → posts.user_id）
     * - morph：多態の列の名前（account なら account_type・account_id。種類が持ち主の表のモデルの行だけ）
     *
     * @param  ?array{0: string, 1: string, 2: string, 3?: string}  $via
     */
    public function erasedWith(string $owner, ?string $column = null, ?array $via = null, ?string $morph = null): self
    {
        $this->refuseWith('erasedWith', kept: true, handled: true);

        if (count(array_filter([$column, $via, $morph], fn ($given) => $given !== null)) !== 1) {
            throw new InvalidArgumentException("{$this->name}：erasedWith() には column・via・morph のどれか1つを渡してください");
        }
        if ($this->anonymize !== null && $this->anonymize['owner'] === $owner) {
            throw new LogicException("{$this->name}：同じ持ち主（{$owner}）で、消すと書き換えるの両方は書けません");
        }

        if ($via !== null) {
            $via = array_values($via);
            if (count($via) < 3 || count($via) > 4 || array_filter($via, fn ($part) => ! is_string($part) || $part === '') !== []) {
                throw new InvalidArgumentException("{$this->name}：via は [親の表, この表の親への列, 親の表の持ち主の列, 親の鍵] の文字列にしてください");
            }
            $via = ['table' => $via[0], 'column' => $via[1], 'ownerColumn' => $via[2], 'key' => $via[3] ?? 'id'];
        }

        $this->erasures[] = ['owner' => $owner, 'column' => $column, 'via' => $via, 'morph' => $morph];

        return $this;
    }

    /**
     * 持ち主の消去で、行を残して列を書き換える。すべての列を erase（書き込む値）か keep（残す）に分ける
     *
     * @param  string  $key  持ち主の鍵を持つ列（持ち主の表なら、その鍵）
     * @param  array<string, scalar|null|Anonymize>  $erase  列 => 書き込む値
     * @param  list<string>  $keep  残す列
     * @param  string  $reason  行を残す理由
     */
    public function anonymizedWith(string $owner, string $key, array $erase, array $keep, string $reason): self
    {
        $this->refuseWith('anonymizedWith', kept: true, handled: true);

        if ($this->anonymize !== null) {
            throw new LogicException("{$this->name}：anonymizedWith() は1つの表に1回だけ書けます");
        }
        foreach ($this->erasures as $erasure) {
            if ($erasure['owner'] === $owner) {
                throw new LogicException("{$this->name}：同じ持ち主（{$owner}）で、消すと書き換えるの両方は書けません");
            }
        }
        if ($erase === []) {
            throw new InvalidArgumentException("{$this->name}：anonymizedWith() の erase に、書き換える列を書いてください");
        }
        foreach ($erase as $column => $value) {
            if (! is_string($column) || ! (is_scalar($value) || $value === null || $value instanceof Anonymize)) {
                throw new InvalidArgumentException("{$this->name}：erase は 列 => 値（文字・数・null・Anonymize）にしてください");
            }
        }
        $both = array_intersect(array_keys($erase), $keep);
        if ($both !== []) {
            throw new LogicException("{$this->name}：列 ".implode('・', $both).' を erase と keep の両方に書いています');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("{$this->name}：行を残す理由（reason）を書いてください");
        }

        $this->anonymize = ['owner' => $owner, 'key' => $key, 'erase' => $erase, 'keep' => array_values($keep), 'reason' => $reason];

        return $this;
    }

    /** 期限で消す Prunable・MassPrunable のモデル（model:prune が schedule にあるかを確かめる） */
    public function prunedBy(string $model): self
    {
        $this->refuseWith('prunedBy', kept: true, handled: false);

        if (! class_exists($model) || ! method_exists($model, 'prunable')) {
            throw new LogicException("{$this->name}：prunedBy() の {$model} は、prunable() を持つモデルにしてください");
        }

        $this->models[] = $model;

        return $this;
    }

    /** 期限で消すコマンド（queue:prune-failed など。schedule にあるかを確かめる） */
    public function prunedByCommand(string $command): self
    {
        $this->refuseWith('prunedByCommand', kept: true, handled: false);

        if (trim($command) === '' || preg_match('/\s/', trim($command))) {
            throw new InvalidArgumentException("{$this->name}：prunedByCommand() にはコマンドの名前だけを渡してください（引数は schedule に書く）");
        }

        $this->commands[] = trim($command);

        return $this;
    }

    /** 消さない。理由を書く（法令で残す請求の写し・個人の情報を持たない表） */
    public function kept(string $reason): self
    {
        if ($this->erasures !== [] || $this->anonymize !== null || $this->models !== [] || $this->commands !== [] || $this->handledBy !== null) {
            throw new LogicException("{$this->name}：kept() は、ほかの消し方と一緒に書けません");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException("{$this->name}：残す理由を書いてください");
        }

        $this->keptReason = $reason;

        return $this;
    }

    /** アプリが自分で消す（ストレージのファイルも消すなど）。どこで消すかを書く。期限の消し方とは一緒に書ける */
    public function handledByApp(string $where): self
    {
        if ($this->erasures !== [] || $this->anonymize !== null || $this->keptReason !== null) {
            throw new LogicException("{$this->name}：handledByApp() は、持ち主の消去・kept() と一緒に書けません");
        }
        if (trim($where) === '') {
            throw new InvalidArgumentException("{$this->name}：どこで消すかを書いてください");
        }

        $this->handledBy = $where;

        return $this;
    }

    /** @internal パッケージの表の印（FoundationDataMap が付ける） */
    public function forComponent(string $configKey): self
    {
        $this->component = $configKey;

        return $this;
    }

    /** @return list<array{owner: string, column: ?string, via: ?array{table: string, column: string, ownerColumn: string, key: string}, morph: ?string}> */
    public function erasures(?string $owner = null): array
    {
        return $owner === null ? $this->erasures : array_values(array_filter($this->erasures, fn ($erasure) => $erasure['owner'] === $owner));
    }

    /** @return ?array{owner: string, key: string, erase: array<string, mixed>, keep: list<string>, reason: string} */
    public function anonymization(): ?array
    {
        return $this->anonymize;
    }

    /** @return list<class-string> */
    public function models(): array
    {
        return $this->models;
    }

    /** @return list<string> */
    public function commands(): array
    {
        return $this->commands;
    }

    public function isKept(): bool
    {
        return $this->keptReason !== null;
    }

    public function handledBy(): ?string
    {
        return $this->handledBy;
    }

    public function component(): ?string
    {
        return $this->component;
    }

    /** 消し方が1つも書かれていない */
    public function isEmpty(): bool
    {
        return $this->erasures === [] && $this->anonymize === null && $this->models === [] && $this->commands === []
            && $this->keptReason === null && $this->handledBy === null;
    }

    /** @return list<string> この表に触れる持ち主の名前 */
    public function owners(): array
    {
        $owners = array_column($this->erasures, 'owner');
        if ($this->anonymize !== null) {
            $owners[] = $this->anonymize['owner'];
        }

        return array_values(array_unique($owners));
    }

    private function refuseWith(string $method, bool $kept, bool $handled): void
    {
        if ($kept && $this->keptReason !== null) {
            throw new LogicException("{$this->name}：{$method}() は kept() と一緒に書けません");
        }
        if ($handled && $this->handledBy !== null) {
            throw new LogicException("{$this->name}：{$method}() は handledByApp() と一緒に書けません");
        }
    }
}
