<?php

namespace Miraqceed\Foundation\Support;

use Illuminate\Support\Facades\DB;

/**
 * DB の接続の時間帯と、アプリの時間帯（app.timezone）がずれていないかを見る。
 *
 * Laravel は日時を時差なしの文字列（アプリの時間帯の時刻）で DB に書く。接続の時間帯がアプリと違うと、
 * PostgreSQL の timestamptz・MySQL の TIMESTAMP に、時差の分ずれた時刻が入る。
 * 時間帯の名前（Asia/Tokyo・+09:00）でなく、今の時差で比べる（名前の書き方が DB ごとに違うため）。
 * 見るのは PostgreSQL と MySQL だけ（SQLite は時刻を文字列で持ち、接続の時間帯がない）。呼んだときだけ働く
 */
final class DatabaseTimezone
{
    /**
     * 接続（null は既定）の DB の今の時差と、アプリの時間帯の今の時差が違えば、分かる文。同じか、見ない DB なら null。
     * 文には、接続の名前・アプリの時間帯・2つの時差だけを入れる（DB のホスト・ユーザー名を入れない）
     */
    public static function mismatch(?string $connection = null): ?string
    {
        $databaseOffset = self::databaseOffset($connection);
        if ($databaseOffset === null) {
            return null;
        }

        $appTimezone = (string) config('app.timezone', 'UTC');
        $appOffset = (new \DateTimeImmutable('now', new \DateTimeZone($appTimezone)))->getOffset();
        if ($appOffset === $databaseOffset) {
            return null;
        }

        $name = $connection ?? (string) config('database.default');

        return sprintf(
            'DB の接続 %s の時間帯（今の時差 %s）が、アプリの時間帯 %s（今の時差 %s）と違います。日時が時差の分ずれて入ります。'
            .'config/database.php の接続に timezone を書いてください',
            $name,
            self::format($databaseOffset),
            $appTimezone,
            self::format($appOffset),
        );
    }

    /** DB の今の時差（秒）。PostgreSQL・MySQL 以外は null */
    public static function databaseOffset(?string $connection = null): ?int
    {
        $db = DB::connection($connection);

        $sql = match ($db->getDriverName()) {
            'pgsql' => 'select extract(timezone from now())::integer as offset_seconds',
            'mysql', 'mariadb' => 'select timestampdiff(second, utc_timestamp(), now()) as offset_seconds',
            default => null,
        };

        return $sql === null ? null : (int) $db->selectOne($sql)->offset_seconds;
    }

    private static function format(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
