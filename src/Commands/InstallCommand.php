<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;

/**
 * アプリの AGENTS.md・CLAUDE.md に、このパッケージの使い方の節を書く。
 * 節の中身は Boost の指針（resources/boost/guidelines/core.blade.php）から作る（原本は1つ）。
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
        return self::START."\n".self::body()."\n".self::END;
    }

    /**
     * 節の本文。原本は Boost が読む指針（GUIDELINE）の1つだけで、ここでは Blade・Boost の書き方を
     * ふつうの Markdown に直すだけ（@verbatim を外す・<code-snippet> を ``` にする・@{{ を {{ にする）。
     * 2つを別々に書くと、片方だけ直して手順が欠ける（Fortify の authenticateUsing が抜けた）。
     */
    public static function body(): string
    {
        $lines = [];
        // \R は /u なしだとバイトの \x85 でも切れ、日本語の文字を割る。改行だけで切る
        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents(self::GUIDELINE)) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '@verbatim' || $trimmed === '@endverbatim') {
                continue;
            }
            if (str_starts_with($trimmed, '<code-snippet') && str_ends_with($trimmed, '>')) {
                $lines[] = '```'.(preg_match('/\blang="([^"]*)"/', $trimmed, $m) ? $m[1] : '');

                continue;
            }
            if ($trimmed === '</code-snippet>') {
                $lines[] = '```';

                continue;
            }
            $lines[] = str_replace('@{{', '{{', $line);
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** AI 向けの指針の原本（Laravel Boost が boost:install で読む置き場所） */
    public const GUIDELINE = __DIR__.'/../../resources/boost/guidelines/core.blade.php';
}
