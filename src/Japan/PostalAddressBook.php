<?php

namespace Miraqceed\Foundation\Japan;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 郵便番号から住所を引く。外のサービスに問い合わせない（利用者の入れた郵便番号を第三者に送らない）。
 *
 * 一覧はアプリが `php artisan foundation:postal-codes` で自分の置き場所（foundation.japan.postal_codes.path、
 * 既定 resources/postal-codes）に作る。上3桁ごとのファイル（`100.json` など）と、元の記録 `source.json`。
 * 置き場所がなければ、どの番号も空（使っていない）
 */
final class PostalAddressBook
{
    /** 日本郵便の更新日からこれより経ったら、deploy-check の postal_codes が作り直しを促す（日本郵便は毎月更新する） */
    public const STALE_MONTHS = 3;

    /** 更新日は日本郵便の日付（日本時間） */
    private const ZONE = 'Asia/Tokyo';

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? self::configuredPath();
    }

    /** 設定の置き場所（foundation.japan.postal_codes.path。空なら resources/postal-codes） */
    public static function configuredPath(): string
    {
        $path = config('foundation.japan.postal_codes.path');

        return is_string($path) && $path !== '' ? rtrim($path, '/\\') : resource_path('postal-codes');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * 住所の候補。番号の形でない・見つからない・置き場所がなければ空。1つの番号に町名が複数あれば、すべて（画面が選ばせる）
     *
     * @return list<array{prefecture: string, city: string, street: string, building?: string}>
     */
    public function lookup(?string $input): array
    {
        $formatted = PostalCode::format($input);
        if ($formatted === null) {
            return [];
        }
        // ファイルの名前は、7桁の数字に確かめた番号の上3桁だけから作る
        $code = str_replace('-', '', $formatted);
        $path = $this->directory.'/'.substr($code, 0, 3).'.json';
        if (! is_file($path)) {
            return [];
        }
        $entries = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)[$code] ?? [];

        return array_map(fn (array $c) => [
            'prefecture' => $c[0], 'city' => $c[1], 'street' => $c[2], ...(isset($c[3]) ? ['building' => $c[3]] : []),
        ], $entries);
    }

    /**
     * 作り直しのコマンドが書いた元の記録（source.json：日本郵便の ZIP の指紋・更新日・番号の数・ファイルの数・データの指紋）。
     * なければ null。読めなければ PostalCodesException
     *
     * @return array<string, mixed>|null
     */
    public function source(): ?array
    {
        $path = $this->directory.'/source.json';
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded) || ! is_string($decoded['updated'] ?? null)
            || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $decoded['updated'], $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new PostalCodesException('source.json を読めません（foundation:postal-codes で作り直してください）');
        }

        return $decoded;
    }

    /** 元の2つ（住所・事業所）の更新日のうち古い方（YYYY-MM-DD）。一覧がなければ null */
    public function updatedOn(): ?string
    {
        return $this->source()['updated'] ?? null;
    }

    /** 日本郵便の更新日から STALE_MONTHS を過ぎたか（日本時間の更新日の3か月後の日の終わりまでは新しい）。一覧がなければ false */
    public function isStale(CarbonInterface $now): bool
    {
        $updated = $this->updatedOn();
        if ($updated === null) {
            return false;
        }
        $limit = CarbonImmutable::parse($updated, self::ZONE)->addMonthsNoOverflow(self::STALE_MONTHS)->endOfDay();

        return $now->greaterThan($limit);
    }
}
