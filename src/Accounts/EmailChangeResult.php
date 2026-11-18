<?php

namespace Miraqceed\Foundation\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * confirm()・undo() の結果。断ったときは status だけ。
 *
 * - 確かめた：oldEmail は前のアドレス（戻すリンクを送る先）、newEmail は切り替えたアドレス。undoToken・undoExpiresAt がある
 * - 戻した：oldEmail は戻す前のアドレス、newEmail は戻したアドレス
 */
final class EmailChangeResult
{
    private function __construct(
        public readonly EmailChangeStatus $status,
        public readonly ?Model $account = null,
        public readonly ?string $oldEmail = null,
        public readonly ?string $newEmail = null,
        /** 平文の戻すトークン（ecu_ + 英数字40字）。古いアドレスへのリンクに入れる。確かめたときだけ */
        public readonly ?string $undoToken = null,
        /** 戻せる期限（アプリの時間帯）。確かめたときだけ */
        public readonly ?CarbonImmutable $undoExpiresAt = null,
    ) {}

    public static function refused(EmailChangeStatus $status): self
    {
        return new self($status);
    }

    public static function confirmedFor(Model $account, string $oldEmail, string $newEmail, #[\SensitiveParameter] string $undoToken, CarbonImmutable $undoExpiresAt): self
    {
        return new self(EmailChangeStatus::Confirmed, $account, $oldEmail, $newEmail, $undoToken, $undoExpiresAt);
    }

    public static function undoneFor(Model $account, string $replacedEmail, string $restoredEmail): self
    {
        return new self(EmailChangeStatus::Undone, $account, $replacedEmail, $restoredEmail);
    }

    public function confirmed(): bool
    {
        return $this->status === EmailChangeStatus::Confirmed;
    }

    public function undone(): bool
    {
        return $this->status === EmailChangeStatus::Undone;
    }
}
