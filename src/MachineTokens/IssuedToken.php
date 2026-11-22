<?php

namespace Miraqceed\Foundation\MachineTokens;

use JsonSerializable;
use LogicException;

/**
 * 出した鍵。平文はここにだけある（表・ログには書かない）。1度だけ渡す（答えには `token` だけを使う）。
 * var_dump()・print_r()・json_encode()・ログの context（Monolog の正規化は JsonSerializable を使う）では平文を伏せ、
 * serialize() は断る（キューのジョブ・キャッシュに平文を残さない）。(array) で読むことは防げない
 */
final class IssuedToken implements JsonSerializable
{
    public function __construct(
        /** 平文の鍵（頭の印 + 英数字40字） */
        #[\SensitiveParameter]
        public readonly string $token,
        /** 種類の名前 */
        public readonly string $kind,
        public readonly string $subject,
    ) {}

    /** var_dump・dump()・print_r に平文を出さない */
    public function __debugInfo(): array
    {
        return $this->withoutToken();
    }

    /** json_encode・ログの context に平文を出さない */
    public function jsonSerialize(): array
    {
        return $this->withoutToken();
    }

    /** @throws LogicException いつも（平文を持つ値をシリアライズしない） */
    public function __serialize(): array
    {
        throw new LogicException('出した鍵はシリアライズできません（平文を残さないため。token だけを1度だけ渡してください）');
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws LogicException いつも
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('出した鍵はシリアライズできません（平文を残さないため。token だけを1度だけ渡してください）');
    }

    /** @return array{token: string, kind: string, subject: string} */
    private function withoutToken(): array
    {
        return ['token' => '[伏せた]', 'kind' => $this->kind, 'subject' => $this->subject];
    }
}
