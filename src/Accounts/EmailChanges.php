<?php

namespace Miraqceed\Foundation\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Miraqceed\Foundation\Accounts\Events\EmailChangeConfirmed;
use Miraqceed\Foundation\Accounts\Events\EmailChangeUndone;
use Miraqceed\Foundation\Login\EmailKey;
use Miraqceed\Foundation\Login\NormalizedEmailKey;
use Miraqceed\Foundation\OneTimeCodes\CodeFormat;

/**
 * メールアドレスの変更：新しいアドレスの確認で切り替え、古いアドレスへの戻すリンクで戻せるようにする。呼んだときだけ働く。
 *
 * - 頼む：前のまだ確かめていない頼みを取り消し、新しい頼みを書く。平文のトークンは戻り値にだけ入れ、表には SHA-256 だけを書く
 * - 確かめる：頼んだ本人のログインの中だけ。握った後に、期限・使った・取り消した・今のアドレス・新しいアドレスの空きを比べ直す
 * - 戻す：その後の変更もたどって戻し、パスワード・remember・セッション・再設定のトークン・頼みを消し、アプリの差し込みを呼ぶ
 * - どの口も、利用者の行 → 変更の行の順に握る。表と利用者のモデルは既定の接続に置く（同じトランザクションに入る）
 * - 時刻は UTC で書いて UTC で比べる（OneTimeCodes と同じ）
 * - 失敗の数えは持たない。リンクの口と頼みの口は Attempts で包む
 */
final class EmailChanges
{
    public const TABLE = 'foundation_email_changes';

    private const CONFIRM_PREFIX = 'ec_';

    private const UNDO_PREFIX = 'ecu_';

    /** トランザクションを試す回数（行き詰まり・直列化の失敗で試し直す） */
    private const ATTEMPTS = 3;

    /** 表に書く時刻の形（UTC） */
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Container $container,
        private readonly PasswordSessions $sessions,
    ) {}

    /** 利用者のモデルのメールアドレスの列（foundation.email_changes.email_column） */
    public static function emailColumn(): string
    {
        $column = config('foundation.email_changes.email_column', 'email');

        return is_string($column) && $column !== '' ? $column : 'email';
    }

    /**
     * 変更を頼む。新しいアドレスは判定しない（形・unique・NotHeldEmail はアプリの検証。確かめるときに比べ直す）。
     *
     * @throws InvalidArgumentException 新しいアドレスが空・255字を超える・壊れた UTF-8 のとき（文に値は入れない）
     */
    public function request(Model&Authenticatable $user, string $newEmail): RequestedEmailChange
    {
        if ($newEmail === '' || ! mb_check_encoding($newEmail, 'UTF-8') || mb_strlen($newEmail, 'UTF-8') > 255) {
            throw new InvalidArgumentException('新しいメールアドレスは、255字までの UTF-8 の文字列にしてください');
        }

        $now = self::now();
        $expiresAt = $now->addMinutes(max(1, (int) config('foundation.email_changes.confirm_minutes', 60)));
        $token = CodeFormat::generate(self::CONFIRM_PREFIX);

        $id = $this->db()->transaction(function () use ($user, $newEmail, $now, $expiresAt, $token): int {
            $account = $this->lockAccount($user::class, (string) $user->getKey()) ?? throw new InvalidArgumentException('利用者の行がありません');
            $this->cancelPendingRows($account, $now);
            $oldEmail = (string) $account->getAttribute(self::emailColumn());

            return (int) $this->table()->insertGetId([
                'account_type' => $account->getMorphClass(),
                'account_id' => (string) $account->getKey(),
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'old_email_key' => $this->emailKey($oldEmail),
                'token_hash' => CodeFormat::hash($token),
                'expires_at' => $expiresAt->format(self::FORMAT),
                'created_at' => $now->format(self::FORMAT),
            ]);
        }, self::ATTEMPTS);

        return new RequestedEmailChange($id, $token, $newEmail, self::toAppTimezone($expiresAt));
    }

    /**
     * 新しいアドレスに届いたリンクで確かめ、切り替える。頼んだ本人がログインしているときだけ（$signedIn）。
     *
     * @param  mixed  $id  リンクの変更の行の id
     * @param  mixed  $token  リンクのトークン（文字列でなければ断る）
     */
    public function confirm(mixed $id, #[\SensitiveParameter] mixed $token, ?Authenticatable $signedIn): EmailChangeResult
    {
        $row = $this->findByToken($id, $token, self::CONFIRM_PREFIX, 'token_hash');
        if ($row === null) {
            return EmailChangeResult::refused(EmailChangeStatus::Invalid);
        }
        if (! $this->isAccountOf($signedIn, $row)) {
            return EmailChangeResult::refused(EmailChangeStatus::NotRequester);
        }

        try {
            /** @var array{EmailChangeResult, ?EmailChangeConfirmed} $outcome */
            $outcome = $this->db()->transaction(fn (): array => $this->confirmLocked($row), self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // 同時の登録・変更が、同じアドレスを先に書いた（巻き戻した後）
            return EmailChangeResult::refused(EmailChangeStatus::Taken);
        }

        [$result, $event] = $outcome;
        if ($event !== null) {
            event($event);
        }

        return $result;
    }

    /**
     * 古いアドレスに届いたリンクで戻す。ログインは求めない。
     * 戻した後、口はこの要求のセッションを invalidate() する（この要求がその利用者でログインしていると、要求の終わりに書き戻される）
     *
     * @param  mixed  $id  リンクの変更の行の id
     * @param  mixed  $token  リンクのトークン（文字列でなければ断る）
     */
    public function undo(mixed $id, #[\SensitiveParameter] mixed $token): EmailChangeResult
    {
        $row = $this->findByToken($id, $token, self::UNDO_PREFIX, 'undo_token_hash');
        if ($row === null) {
            return EmailChangeResult::refused(EmailChangeStatus::Invalid);
        }

        /** @var array{EmailChangeResult, ?EmailChangeUndone} $outcome */
        $outcome = $this->db()->transaction(fn (): array => $this->undoLocked($row), self::ATTEMPTS);

        [$result, $event] = $outcome;
        if ($event !== null) {
            event($event);
        }

        return $result;
    }

    /** まだ確かめていない頼みを取り消し、その数を返す（再設定・パスワードの変更の後に呼ぶ） */
    public function cancelPending(Model&Authenticatable $user): int
    {
        return $this->db()->transaction(function () use ($user): int {
            $account = $this->lockAccount($user::class, (string) $user->getKey());

            return $account === null ? 0 : $this->cancelPendingRows($account, self::now());
        }, self::ATTEMPTS);
    }

    /**
     * 押さえられているか：確かめて戻していない変更の古いアドレスで、戻す期限の前のもの。比べは EmailKey。
     * $for のアカウントの押さえは数えない（本人は古いアドレスへ戻す変更を頼める）
     */
    public function isHeld(string $email, ?Model $for = null): bool
    {
        $query = $this->table()
            ->where('old_email_key', $this->emailKey($email))
            ->whereNotNull('confirmed_at')
            ->whereNull('undone_at')
            ->where('undo_expires_at', '>', self::now()->format(self::FORMAT));

        if ($for !== null) {
            $query->whereNot(fn ($own) => $own
                ->where('account_type', $for->getMorphClass())
                ->where('account_id', (string) $for->getKey()));
        }

        return $query->exists();
    }

    /** @return array{EmailChangeResult, ?EmailChangeConfirmed} */
    private function confirmLocked(object $row): array
    {
        $now = self::now();
        $account = $this->lockAccountOf($row);
        $locked = $this->table()->where('id', $row->id)->lockForUpdate()->first();
        if ($account === null || $locked === null) {
            return [EmailChangeResult::refused(EmailChangeStatus::Invalid), null];
        }

        // 握った行で比べ直す
        if ($locked->cancelled_at !== null) {
            return [EmailChangeResult::refused(EmailChangeStatus::Cancelled), null];
        }
        if ($locked->confirmed_at !== null || $locked->undone_at !== null) {
            return [EmailChangeResult::refused(EmailChangeStatus::Used), null];
        }
        if (self::fromStored((string) $locked->expires_at)->lessThanOrEqualTo($now)) {
            return [EmailChangeResult::refused(EmailChangeStatus::Expired), null];
        }

        $column = self::emailColumn();
        $oldEmail = (string) $locked->old_email;
        $newEmail = (string) $locked->new_email;
        if ((string) $account->getAttribute($column) !== $oldEmail) {
            return [EmailChangeResult::refused(EmailChangeStatus::Changed), null];
        }
        if ($this->availability()->isTaken($newEmail, $account)) {
            return [EmailChangeResult::refused(EmailChangeStatus::Taken), null];
        }
        if ($this->isHeld($newEmail, $account)) {
            return [EmailChangeResult::refused(EmailChangeStatus::Held), null];
        }

        // 古いアドレスで頼まれた再設定のトークンは、アドレスを替える前に消す
        $this->deleteResetTokens($account, [$oldEmail]);

        $account->setAttribute($column, $newEmail);
        if ($account instanceof MustVerifyEmail) {
            $account->setAttribute('email_verified_at', $account->freshTimestamp());
        }
        $account->save();

        $undoToken = CodeFormat::generate(self::UNDO_PREFIX);
        $undoExpiresAt = $now->addDays(max(1, (int) config('foundation.email_changes.undo_days', 7)));
        $this->table()->where('id', $locked->id)->update([
            'confirmed_at' => $now->format(self::FORMAT),
            'undo_token_hash' => CodeFormat::hash($undoToken),
            'undo_expires_at' => $undoExpiresAt->format(self::FORMAT),
            'snapshot' => $this->encodeSnapshot($this->lockdown()->snapshot($account)),
        ]);

        return [
            EmailChangeResult::confirmedFor($account, $oldEmail, $newEmail, $undoToken, self::toAppTimezone($undoExpiresAt)),
            new EmailChangeConfirmed($account, (int) $locked->id, $oldEmail, $newEmail),
        ];
    }

    /** @return array{EmailChangeResult, ?EmailChangeUndone} */
    private function undoLocked(object $row): array
    {
        $now = self::now();
        $account = $this->lockAccountOf($row);
        if ($account === null) {
            return [EmailChangeResult::refused(EmailChangeStatus::Invalid), null];
        }

        // このアカウントの、この行から後の変更を id の順に握る
        $rows = $this->table()
            ->where('account_type', $row->account_type)
            ->where('account_id', $row->account_id)
            ->where('id', '>=', $row->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $locked = $rows->first(fn (object $candidate): bool => (int) $candidate->id === (int) $row->id);
        if ($locked === null) {
            return [EmailChangeResult::refused(EmailChangeStatus::Invalid), null];
        }
        if ($locked->undone_at !== null) {
            return [EmailChangeResult::refused(EmailChangeStatus::Used), null];
        }
        if ($locked->undo_expires_at === null || self::fromStored((string) $locked->undo_expires_at)->lessThanOrEqualTo($now)) {
            return [EmailChangeResult::refused(EmailChangeStatus::Expired), null];
        }

        // たどる：確かめて戻していない行が、前の new_email = 次の old_email でつながり、最後が今のアドレス。
        // 頼みは1つずつなので、確かめた順は id の順と同じ
        $chain = $rows->filter(fn (object $change): bool => $change->confirmed_at !== null && $change->undone_at === null)->values();
        $column = self::emailColumn();
        $currentEmail = (string) $account->getAttribute($column);
        foreach ($chain as $index => $change) {
            if ($index > 0 && (string) $change->old_email !== (string) $chain[$index - 1]->new_email) {
                return [EmailChangeResult::refused(EmailChangeStatus::Changed), null];
            }
        }
        if ((string) $chain->last()->new_email !== $currentEmail) {
            return [EmailChangeResult::refused(EmailChangeStatus::Changed), null];
        }

        $restoredEmail = (string) $locked->old_email;
        // 押さえの前からある古いアカウントだけがありうる
        if ($this->availability()->isTaken($restoredEmail, $account)) {
            return [EmailChangeResult::refused(EmailChangeStatus::Taken), null];
        }

        // たどったすべてのアドレスで頼まれた再設定のトークン（乗っ取った人のアドレスの分も）
        $emails = [];
        foreach ($chain as $change) {
            $emails[] = (string) $change->old_email;
            $emails[] = (string) $change->new_email;
        }
        $this->deleteResetTokens($account, array_values(array_unique($emails)));

        // パッケージが必ず行うこと：アドレス・パスワード・remember・すべてのセッション
        $account->setAttribute($column, $restoredEmail);
        if ($account instanceof MustVerifyEmail) {
            // 古いアドレスに届いたリンクで、本人が確かめた
            $account->setAttribute('email_verified_at', $account->freshTimestamp());
        }
        // 乱数の値のハッシュ（列が null を許さないアプリでも動く。持ち主は古いアドレスへの再設定で入り直す）
        $passwordColumn = method_exists($account, 'getAuthPasswordName') ? $account->getAuthPasswordName() : 'password';
        $account->setAttribute($passwordColumn, Hash::make(Str::random(64)));
        $account->save();
        $this->sessions->afterReset($account);

        $at = $now->format(self::FORMAT);
        $this->cancelPendingRows($account, $now);
        $changeIds = $chain->map(fn (object $change): int => (int) $change->id)->all();
        $this->table()->whereIn('id', $changeIds)->update(['undone_at' => $at]);

        // アプリの差し込み。投げたら全部巻き戻る
        $this->lockdown()->lockdown($account, $this->decodeSnapshot($locked->snapshot));

        return [
            EmailChangeResult::undoneFor($account, $currentEmail, $restoredEmail),
            new EmailChangeUndone($account, $changeIds, $restoredEmail, $currentEmail),
        ];
    }

    /** 形を見て、行を id で読み、ハッシュを比べる。どれかが違えば null（理由を分けない。形が違えば DB を引かない） */
    private function findByToken(mixed $id, #[\SensitiveParameter] mixed $token, string $prefix, string $column): ?object
    {
        if (! CodeFormat::matches($prefix, $token) || ! self::isId($id)) {
            return null;
        }

        $row = $this->table()->where('id', (int) $id)->first();
        $stored = $row?->{$column};
        if (! is_string($stored) || ! hash_equals($stored, CodeFormat::hash($token))) {
            return null;
        }

        return $row;
    }

    private static function isId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }

        // bigint に収まる正の整数の字
        return is_string($id) && preg_match('/\A[1-9][0-9]{0,17}\z/', $id) === 1;
    }

    private function isAccountOf(?Authenticatable $signedIn, object $row): bool
    {
        return $signedIn instanceof Model
            && $signedIn->getMorphClass() === (string) $row->account_type
            && (string) $signedIn->getKey() === (string) $row->account_id;
    }

    /** 変更の行のアカウントを握る。モデルの種類が分からない・行が無ければ null */
    private function lockAccountOf(object $row): ?Model
    {
        $class = Relation::getMorphedModel((string) $row->account_type) ?? (string) $row->account_type;
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $this->lockAccount($class, (string) $row->account_id);
    }

    /** @param  class-string<Model>  $class */
    private function lockAccount(string $class, string $id): ?Model
    {
        $account = (new $class)->newQueryWithoutScopes()->whereKey($id)->lockForUpdate()->first();
        if ($account !== null && ! $account instanceof Authenticatable) {
            throw new InvalidArgumentException('メールアドレスの変更の利用者のモデルは Authenticatable にしてください');
        }

        return $account;
    }

    private function cancelPendingRows(Model $account, CarbonImmutable $now): int
    {
        return $this->table()
            ->where('account_type', $account->getMorphClass())
            ->where('account_id', (string) $account->getKey())
            ->whereNull('confirmed_at')
            ->whereNull('undone_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => $now->format(self::FORMAT)]);
    }

    /**
     * 再設定のトークンを、挙げたアドレスの分だけ消す（foundation.email_changes.password_broker。false なら消さない）。
     * ブローカーの倉庫は、利用者の今のアドレスで消す。アドレスを差し替えた写しを渡す
     *
     * @param  list<string>  $emails
     */
    private function deleteResetTokens(Model $account, array $emails): void
    {
        $broker = config('foundation.email_changes.password_broker');
        if ($broker === false || ! $account instanceof CanResetPassword) {
            return;
        }

        $repository = Password::broker(is_string($broker) && $broker !== '' ? $broker : null)->getRepository();
        foreach ($emails as $email) {
            $probe = clone $account;
            $probe->setAttribute(self::emailColumn(), $email);
            $repository->delete($probe);
        }
    }

    private function emailKey(string $email): string
    {
        $key = $this->configured('email_key', EmailKey::class, NormalizedEmailKey::class)->key($email);
        if (strlen($key) > 64) {
            throw new InvalidArgumentException('foundation.email_changes.email_key の値は64字までにしてください（表の列の長さ）');
        }

        return $key;
    }

    private function availability(): EmailAvailability
    {
        return $this->configured('availability', EmailAvailability::class, ModelEmailAvailability::class);
    }

    private function lockdown(): AccountLockdown
    {
        return $this->configured('lockdown', AccountLockdown::class, NoLockdown::class);
    }

    /**
     * 設定のクラスを容器から作る。書き違い（クラスが無い・契約を満たさない）は、黙って既定に寄せずに止める
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  class-string<T>  $default
     * @return T
     */
    private function configured(string $key, string $contract, string $default): object
    {
        $class = config("foundation.email_changes.{$key}") ?? $default;
        if (! is_string($class) || ! class_exists($class) || ! is_a($class, $contract, true)) {
            throw new InvalidArgumentException("foundation.email_changes.{$key} は {$contract} を実装するクラス名にしてください");
        }

        return $this->container->make($class);
    }

    /** @param  array<array-key, mixed>  $snapshot */
    private function encodeSnapshot(array $snapshot): ?string
    {
        if ($snapshot === []) {
            return null;
        }

        try {
            return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('AccountLockdown::snapshot() の値を JSON にできません（壊れた UTF-8・NAN など）', 0, $e);
        }
    }

    /** @return array<array-key, mixed> */
    private function decodeSnapshot(mixed $stored): array
    {
        return is_string($stored) && $stored !== '' ? (array) json_decode($stored, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function db(): Connection
    {
        return DB::connection();
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->db()->table(self::TABLE);
    }

    /** 今（UTC、秒で切り捨て） */
    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private static function fromStored(string $value): CarbonImmutable
    {
        // PostgreSQL・MySQL・SQLite のどれも 'Y-m-d H:i:s' で返す（秒より下を持たない列）
        return CarbonImmutable::parse(substr($value, 0, 19), 'UTC');
    }

    private static function toAppTimezone(CarbonImmutable $utc): CarbonImmutable
    {
        return $utc->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
