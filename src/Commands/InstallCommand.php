<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;

/**
 * アプリの AGENTS.md・CLAUDE.md に、このパッケージの使い方の節を書く。
 * 節は <!-- foundation:start --> と <!-- foundation:end --> の間だけを置き換えるので、何度流しても1つ。
 * どちらのファイルもなければ AGENTS.md を作る。部品が無効でも流せる（いつも登録する）。
 */
class InstallCommand extends Command
{
    public const START = '<!-- foundation:start -->';

    public const END = '<!-- foundation:end -->';

    protected $signature = 'foundation:install';

    protected $description = 'AI 向けの指針（AGENTS.md・CLAUDE.md）に miraqceed/laravel-foundation の節を書く';

    public function handle(): int
    {
        $files = array_values(array_filter(
            [base_path('AGENTS.md'), base_path('CLAUDE.md')],
            'is_file',
        ));
        if ($files === []) {
            $files = [base_path('AGENTS.md')];
        }

        foreach ($files as $file) {
            $current = is_file($file) ? (string) file_get_contents($file) : '';
            file_put_contents($file, self::replaceSection($current, self::section()));
            $this->info(basename($file).' に節を書きました');
        }

        return self::SUCCESS;
    }

    /** 印の間を置き換える。なければ末尾に足す。印が壊れて対にならないときも、節を1つに保つ */
    public static function replaceSection(string $text, string $section): string
    {
        $start = strpos($text, self::START);
        $end = $start === false ? false : strpos($text, self::END, $start);

        if ($start !== false && $end !== false) {
            return substr($text, 0, $start).$section.substr($text, $end + strlen(self::END));
        }

        $separator = $text === '' ? '' : (str_ends_with($text, "\n") ? "\n" : "\n\n");

        return $text.$separator.$section."\n";
    }

    public static function section(): string
    {
        return self::START."\n".self::BODY."\n".self::END;
    }

    private const BODY = <<<'MD'
## miraqceed/laravel-foundation

どのアプリにも要る部品（テストの守り・CSV・ログインのロック・CSP・見張り）。部品は `config/foundation.php` で有効にする（入れただけでは挙動は変わらない）。
詳しい手順は `vendor/miraqceed/laravel-foundation/docs/` の `test-safety.md`・`csv.md`・`login.md`・`security.md`・`monitoring.md`。**自前で書き直す前に読む。**

- **テストの守り**：テスト用 DB の名前に `test` か `testing` を語として入れる（`app_test`）。環境変数で接続先を渡さない。外の DB は `foundation.test_safety.ignore_connections` に接続名を挙げる（名前を偽らない）。
- **CSV**：書き出しは `CsvResponse::download()`。式の守りを自前で書かない。`lazy()`・`chunk()` で読まない。大きな表は `Rows::keyset()`（一意の列を非修飾の名前で渡す）。読み込みは `CsvReader::parse()`（Shift_JIS 対応）。
- **ログイン**：失敗の数えを自前で作らない。`LoginLock` を使う。Fortify では `fortify.limiters.login` を `null` にし、`Fortify::authenticateThrough(fn () => AuthenticateWithLoginLock::loginPipeline())` と `Fortify::authenticateUsing(...)` を渡す。
- **CSP**：`'unsafe-inline'` を足して済ませない。外の元は `foundation.security.areas` に足す。ミドルウェア `foundation.security-headers` は自分で `web` のグループに足す。報告の口（`report_route`）は `web` のグループに入れない。
- **見張り**：schedule の処理には `->watched('名前', 許す古さ（分）)`。schedule の外の処理は終わりに `php artisan foundation:record 名前` を呼び、`foundation.monitoring.external` に書く。知らせの送り先は `foundation.monitoring.alerts.channels`（MiraqStudio の顧客のアプリは `log`）。
MD;
}
