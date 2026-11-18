<?php

namespace Miraqceed\Foundation\Accounts;

use Carbon\CarbonImmutable;

/** 頼んだメールアドレスの変更。平文のトークンはここにだけある（表・ログには書かない）。新しいアドレスへのリンクに入れる */
final class RequestedEmailChange
{
    public function __construct(
        /** 変更の行の id（リンクに入れる） */
        public readonly int $id,
        /** 平文の確認のトークン（ec_ + 英数字40字） */
        public readonly string $token,
        public readonly string $newEmail,
        /** 期限（アプリの時間帯）。この時刻からは確かめられない */
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
