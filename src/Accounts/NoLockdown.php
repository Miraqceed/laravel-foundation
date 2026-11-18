<?php

namespace Miraqceed\Foundation\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/** 既定の差し込み。何も残さず、何も消さない（パッケージが必ず行うことだけになる） */
final class NoLockdown implements AccountLockdown
{
    public function snapshot(Model&Authenticatable $user): array
    {
        return [];
    }

    public function lockdown(Model&Authenticatable $user, array $snapshot): void {}
}
