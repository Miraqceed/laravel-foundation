<?php

namespace Miraqceed\Foundation\OneTimeCodes;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * 1回だけ使える合言葉の行。古い行を model:prune で消すためのモデル（出す・受け取るは OneTimeCodes で行う）。
 * 時刻の列は UTC の文字列（時差なしの dateTime）。datetime に型づけしない（アプリの時間帯の時刻と読まれる）。
 * アプリが schedule に入れる：Schedule::command('model:prune', ['--model' => [OneTimeCode::class]])->daily();
 *
 * @property int $id
 * @property string $kind
 * @property string $code_hash
 * @property ?string $subject
 * @property string $expires_at UTC
 * @property ?string $claimed_at UTC
 * @property ?string $revoked_at UTC
 * @property string $created_at UTC
 */
class OneTimeCode extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = OneTimeCodes::TABLE;

    protected $guarded = [];

    /** 期限・使った時刻・失効の時刻のどれかが prune_days 日（既定 7。1未満は1）より前の行 */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('foundation.one_time_codes.prune_days', 7));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        return static::query()->where(fn (Builder $query) => $query
            ->where('expires_at', '<', $cutoff)
            ->orWhere('claimed_at', '<', $cutoff)
            ->orWhere('revoked_at', '<', $cutoff));
    }
}
