<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Accounts\AccountLockdown;
use RuntimeException;

/** 試しの差し込み：確かめたときに連携の id を残し、戻すときに呼ばれた中身を覚える（throws なら投げる） */
class RecordingLockdown implements AccountLockdown
{
    /** @var list<array{id: mixed, snapshot: array<array-key, mixed>, inTransaction: bool}> */
    public static array $lockdowns = [];

    public static bool $throws = false;

    public static function reset(): void
    {
        self::$lockdowns = [];
        self::$throws = false;
    }

    public function snapshot(Model&Authenticatable $user): array
    {
        return ['linked' => ['google:'.$user->getKey()]];
    }

    public function lockdown(Model&Authenticatable $user, array $snapshot): void
    {
        self::$lockdowns[] = ['id' => $user->getKey(), 'snapshot' => $snapshot, 'inTransaction' => DB::transactionLevel() > 0];

        if (self::$throws) {
            throw new RuntimeException('連携を消せなかった');
        }
    }
}
