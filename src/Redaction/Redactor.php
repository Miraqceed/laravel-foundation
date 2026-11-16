<?php

namespace Miraqceed\Foundation\Redaction;

/**
 * 文の中の秘密（鍵・トークン・パスワード・メールアドレス・電話番号）を形で見つけて、印に替える。
 *
 * **漏れを無くすとは約束しない。** 形で見つけられるものだけを伏せる。人の名前・顧客の名前・形の決まっていない秘密は見つけられない。
 * 呼んだときだけ働く。DB・キャッシュを使わない。
 */
final class Redactor
{
    /** 組み込みの種類。当てる順（細かい種類 → アプリが足した形 → 汎用の形） */
    public const KINDS = [
        'private_key', 'aws_key', 'github_token', 'api_key', 'slack_token', 'jwt',
        'assignment', 'url_password', 'email', 'phone',
    ];

    /** アプリが足した形を、この種類の前に当てる（足した形を細かい種類として数える） */
    private const ADDED_BEFORE = 'assignment';

    /**
     * 種類 => 形。
     *
     * - 語の境目は \b を使わず ASCII の英数字の前後読みで見る（/u では \b が日本語も語の字とみなし、「鍵AKIA…を」を逃す）
     * - 次の字を含まない字の組の繰り返しは独占的（++・*+）にする。当たり方は同じで、長い行で PCRE の JIT の上限に掛からない
     */
    private const PATTERNS = [
        // -----BEGIN … PRIVATE KEY----- から END まで。行をまたぐ
        'private_key' => '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/su',
        'aws_key' => '/(?<![A-Za-z0-9])AKIA[0-9A-Z]{16}(?![A-Za-z0-9])/u',
        'github_token' => '/(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{20,})/u',
        'api_key' => '/(?<![A-Za-z0-9])sk-(?:ant-)?[A-Za-z0-9_-]{20,}/u',
        'slack_token' => '/(?<![A-Za-z0-9])xox[abprs]-[A-Za-z0-9-]{10,}/u',
        // 3つの部分。各部 10字以上
        'jwt' => '/(?<![A-Za-z0-9])eyJ[A-Za-z0-9_-]{7,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/u',
        // 鍵の名前・区切り・値。値だけ伏せる。
        // 名前は鍵の語の後に英数字・_・- が続いてよい（AWS_SECRET_ACCESS_KEY・SECRET_KEY_BASE・secret-key）。
        // 区切りは : = =>。名前を閉じる引用符（JSON・PHP の配列）と添字の ]（$cfg['password'] = …）も区切りに含めて残す。
        // 値は2通り。引用符で始まり同じ閉じ引用符が後にあれば、その手前までの ASCII の字（空白・記号も含む）。
        // そうでなければ、空白か日本語の手前までの ASCII の字（記号も含む）。後者は、後ろの閉じ括弧・引用符・, ; と
        // 頭の開き引用符を値から外す（assignmentReplacement）。8字未満は伏せない（「token: 期限切れで再発行した」を値と読まない）
        'assignment' => '/(?<![A-Za-z0-9])((?:password|passwd|secret|token|api[_-]?key)[A-Za-z0-9_-]*["\']?\]?\s*(?:=>|[:=])\s*)(["\'])?((?(2)(?:(?!\2)[\x20-\x7E])++(?=\2)|[\x21-\x7E]+))/iu',
        // 接続文字列（postgres://user:password@host）のパスワードだけ。利用者名は無くてよい（redis://:password@host）。
        // email より先に当て、user:pass@host を半端にしない
        'url_password' => '/(?<![A-Za-z0-9+.-])([A-Za-z][A-Za-z0-9+.-]*:\/\/(?:(?![:\/@])[\x21-\x7E])*+:)((?:(?![\/@])[\x21-\x7E])++)(?=@)/u',
        'email' => '/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?![A-Za-z0-9])/u',
        // 日本の電話番号。ハイフン2つで数字は 10〜11桁（全体 12〜13字）。日付 2026-11-14 や 09-30-2026 は読まない
        'phone' => '/(?<![A-Za-z0-9.-])(?=[0-9-]{12,13}(?![0-9-]))0\d{1,4}-\d{1,4}-\d{3,4}(?![0-9-])/u',
    ];

    /**
     * 字数で切る口で、伏せる前に荒く切るときに、上限に足す余白の字数。とても長い文の全文に正規表現を当てないため。
     * 組み込みの形でいちばん長い PEM の秘密鍵（RSA 8192 でも約 6,400字）より長くする
     */
    private const SAFETY_TAIL_CHARS = 8000;

    private readonly string $marker;

    /** @var array<string, string> 種類 => 形。当てる順 */
    private readonly array $patterns;

    /**
     * @param  string  $marker  伏せた所に入れる印。どの形にも当たらない字にする
     * @param  list<string>|null  $kinds  使う組み込みの種類。null は全部
     * @param  array<string, string>  $patterns  アプリが足す形（種類の名前 => 正規表現）
     *
     * @throws InvalidRedactionConfig
     */
    public function __construct(string $marker = '[REDACTED]', ?array $kinds = null, array $patterns = [])
    {
        if ($marker === '' || ! mb_check_encoding($marker, 'UTF-8')) {
            throw new InvalidRedactionConfig('伏せる印（foundation.redaction.marker）は、空でない UTF-8 の文にしてください');
        }

        $kinds ??= self::KINDS;
        $unknown = array_values(array_filter($kinds, fn ($kind) => ! is_string($kind) || ! in_array($kind, self::KINDS, true)));
        if ($unknown !== []) {
            throw new InvalidRedactionConfig('知らない種類があります（foundation.redaction.kinds）: '.implode(', ', array_map(fn ($k) => is_string($k) ? $k : get_debug_type($k), $unknown)));
        }

        foreach ($patterns as $name => $pattern) {
            if (! is_string($name) || $name === '' || is_numeric($name)) {
                throw new InvalidRedactionConfig('足す形（foundation.redaction.patterns）には、種類の名前を鍵にしてください');
            }
            if (in_array($name, self::KINDS, true)) {
                throw new InvalidRedactionConfig("足す形の名前 {$name} は、組み込みの種類と同じです。別の名前にしてください");
            }
            if (! is_string($pattern) || ! self::compiles($pattern)) {
                throw new InvalidRedactionConfig("足す形 {$name} の正規表現を作れません");
            }
        }

        $ordered = [];
        foreach (self::KINDS as $kind) {
            if ($kind === self::ADDED_BEFORE) {
                $ordered += $patterns;
            }
            if (in_array($kind, $kinds, true)) {
                $ordered[$kind] = self::PATTERNS[$kind];
            }
        }

        // 印がどれかの形に当たると、伏せた後の文をもう一度伏せたときに、印をまた数える
        foreach ($ordered as $kind => $pattern) {
            if (preg_match($pattern, $marker) === 1) {
                throw new InvalidRedactionConfig("伏せる印が、種類 {$kind} の形に当たります。どの形にも当たらない印にしてください");
            }
        }

        $this->marker = $marker;
        $this->patterns = $ordered;
    }

    /**
     * 設定（foundation.redaction）から作る
     *
     * @throws InvalidRedactionConfig
     */
    public static function fromConfig(): self
    {
        $config = config('foundation.redaction', []);
        $marker = $config['marker'] ?? '[REDACTED]';
        $kinds = $config['kinds'] ?? null;
        $patterns = $config['patterns'] ?? [];

        if (! is_string($marker) || ($kinds !== null && ! is_array($kinds)) || ! is_array($patterns)) {
            throw new InvalidRedactionConfig('foundation.redaction の marker は文、kinds は種類の並びか null、patterns は名前 => 正規表現の配列にしてください');
        }

        return new self($marker, $kinds === null ? null : array_values($kinds), $patterns);
    }

    /**
     * 1つの文を伏せる
     *
     * @throws UnreadableText 壊れた UTF-8・正規表現の失敗（文の中身は例外の文に入れない）
     */
    public function text(string $text): Redacted
    {
        $findings = [];
        $redacted = $this->redactString($text, '', $findings);

        return new Redacted($redacted, $findings);
    }

    /**
     * 入れ子の配列の文字列の値を全部伏せる。キー・文字列でない値には触れない（形は元のまま）。
     * キーに入った秘密は残るので、知らない形の入れ子は、知っている鍵だけに写してから渡す
     *
     * @param  array<mixed>  $data
     *
     * @throws UnreadableText
     */
    public function values(array $data): Redacted
    {
        $findings = [];
        $redacted = $this->walk($data, '', $findings);

        return new Redacted($redacted, $findings);
    }

    /**
     * 伏せてから、字数（バイトでなく字）で切る。出力はいつも $maxChars 字以下
     *
     * @throws UnreadableText
     */
    public function truncate(string $text, int $maxChars): Redacted
    {
        if ($maxChars < 0) {
            throw new \InvalidArgumentException('切る字数は 0 以上にしてください');
        }

        $findings = [];
        $roughChars = $maxChars + self::SAFETY_TAIL_CHARS;
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw self::unreadable('', 'Malformed UTF-8 characters, possibly incorrectly encoded');
        }
        $cut = mb_strlen($text) > $roughChars;
        $redacted = $this->redactString($cut ? mb_substr($text, 0, $roughChars) : $text, '', $findings);

        if ($cut) {
            // 荒い境目で切れた秘密の欠片は、形に合わずに伏せた後の文の末尾に残る。手前の秘密が伏せて縮むと、上限の内に入ってくる。
            // 欠片は末尾にあり余白より短いので、余白の分を捨てれば必ず消える（欠片があるかは見分けずに捨てる）
            $redacted = mb_substr($redacted, 0, max(0, mb_strlen($redacted) - self::SAFETY_TAIL_CHARS));
        }

        // 切ったことで、前は形に合わなかった文が形に合うことがある（03-1234-56789 → 03-1234-5678）。もう一度当てる
        $redacted = $this->redactString(mb_substr($redacted, 0, $maxChars), '', $findings);

        // もう一度当てると、印が当たった文より長いと上限を超える（6字の a@b.jp が10字の印になる）。上限で切って終える。
        // 末尾に印の欠片が残りうる（限界）
        return new Redacted(mb_substr($redacted, 0, $maxChars), $findings);
    }

    public function marker(): string
    {
        return $this->marker;
    }

    /**
     * 当てる種類（組み込みは KINDS の順、足した形は後ろ）
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $builtIn = array_values(array_filter(self::KINDS, fn ($kind) => isset($this->patterns[$kind])));

        return [...$builtIn, ...array_values(array_diff(array_keys($this->patterns), self::KINDS))];
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<Finding>  $findings
     * @return array<mixed>
     */
    private function walk(array $node, string $prefix, array &$findings): array
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $path, $findings);
            } elseif (is_string($value)) {
                $node[$key] = $this->redactString($value, $this->safePath($path), $findings);
            }
        }

        return $node;
    }

    /**
     * 見つけたもの・例外の文に載せる場所。キーに入った秘密が、そこから漏れないように形を当てる。
     * 壊れた UTF-8 のキーは読める形に直してから当てる（例外の文を読めるように）
     */
    private function safePath(string $path): string
    {
        if (! mb_check_encoding($path, 'UTF-8')) {
            $path = mb_scrub($path, 'UTF-8');
        }

        foreach ($this->patterns as $kind => $pattern) {
            $replaced = preg_replace_callback($pattern, fn (array $m) => $this->replacement($kind, $m) ?? $m[0], $path);
            if ($replaced === null) {
                // 場所を確かめられないときは、場所を出さない
                return $this->marker;
            }
            $path = $replaced;
        }

        return $path;
    }

    /**
     * @param  string  $path  例外の文と見つけたものに載せる場所（形を当てた後のもの）
     * @param  list<Finding>  $findings
     *
     * @throws UnreadableText
     */
    private function redactString(string $text, string $path, array &$findings): string
    {
        // 足した形が /u を持たなくても、壊れた UTF-8 を素通りさせない
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw self::unreadable($path, 'Malformed UTF-8 characters, possibly incorrectly encoded');
        }

        foreach ($this->patterns as $kind => $pattern) {
            $replaced = preg_replace_callback(
                $pattern,
                function (array $m) use ($kind, $path, &$findings): string {
                    $replacement = $this->replacement($kind, $m);
                    if ($replacement === null) {
                        return $m[0];
                    }
                    $findings[] = new Finding($kind, $path);

                    return $replacement;
                },
                $text,
            );

            // JIT の上限など。確かめられない文は素通りさせず止める
            if ($replaced === null) {
                throw self::unreadable($path, preg_last_error_msg());
            }
            $text = $replaced;
        }

        return $text;
    }

    /**
     * 当たった所を何に替えるか。秘密と読まないときは null（元のまま残す）
     *
     * @param  array<int|string, string>  $m
     */
    private function replacement(string $kind, array $m): ?string
    {
        // 印そのもの・印の欠片を、値として伏せ直さず数えない。細かい種類で伏せた後の文に汎用の形を当てると、
        // 「token=印」の印が値に読まれる（同じ文をもう一度伏せたときも同じ）。伏せる所から印を除いた残りが、
        // その形の最短に満たなければ伏せない
        $secret = match ($kind) {
            'assignment' => $m[3],
            'url_password' => $m[2],
            default => $m[0],
        };
        $rest = str_replace($this->marker, '', $secret);
        if ($kind === 'assignment') {
            // 引用符のない値だけ、assignmentReplacement と同じく頭の引用符と後ろの括弧などを外して数える
            if ($m[2] === '') {
                $rest = rtrim(ltrim($rest, '"\''), ')]}>"\'`,;');
            }
            if (strlen($rest) < 8) {
                return null;
            }
        } elseif ($rest === '') {
            return null;
        }

        return match ($kind) {
            'assignment' => $this->assignmentReplacement($m[1], $m[2], $m[3]),
            // scheme://user: までを残す
            'url_password' => $m[1].$this->marker,
            default => $this->marker,
        };
    }

    /**
     * 鍵の名前・区切り・開きの引用符を残し、値だけ伏せる。
     * 引用符で閉じていない値は、頭の開き引用符（閉じ引用符が無かったもの）と、後ろの閉じ括弧・引用符・, ; を
     * 値から外して残す（「password: "abcd…」「（password=…)」「password=…, user=…」）。
     * 外すのは ASCII の字だけ（trim の字の組はバイトの集まりなので、日本語の字を入れると文を壊す）
     */
    private function assignmentReplacement(string $key, string $quote, string $value): ?string
    {
        $tail = '';
        if ($quote === '') {
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $key .= $value[0];
                $value = substr($value, 1);
            }
            $core = rtrim($value, ')]}>"\'`,;');
            $tail = substr($value, strlen($core));
            $value = $core;
        }

        return strlen($value) < 8 ? null : $key.$quote.$this->marker.$tail;
    }

    private static function unreadable(string $path, string $reason): UnreadableText
    {
        $where = $path === '' ? '' : "（{$path}）";

        return new UnreadableText("伏せる前の確かめができませんでした{$where}: {$reason}");
    }

    /** 作れる正規表現か。作れない式の警告は、ここで受けて捨てる */
    private static function compiles(string $pattern): bool
    {
        set_error_handler(fn () => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
