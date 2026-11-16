<?php

namespace Miraqceed\Foundation\DeployCheck;

/**
 * 調べの結果の種類。値は JSON の綴り（変えない。CI が読む）
 */
enum Status: string
{
    /** 満たしている */
    case Ok = 'ok';

    /** 満たしていない。終わりの値が 1 になる */
    case Fail = 'fail';

    /** まだ要らない（アプリのフラグで決める）。--strict のときだけ失敗とみなす */
    case Later = 'later';

    /** このアプリには当たらない */
    case Skip = 'skip';

    /** 判断しない。状態を見せるだけ */
    case Info = 'info';

    /** 文字の出力の印（[ok] など） */
    public function mark(): string
    {
        return $this === self::Fail ? 'FAIL' : $this->value;
    }
}
