<?php

namespace Miraqceed\Foundation\Japan;

/**
 * 日本郵便の公開のデータから、郵便番号の一覧（上3桁ごとの JSON）を作る決まり。決まりはここの1か所に置く。
 *
 * 元は2つ。どちらも日本郵便が「著作権を主張しません。自由に配布していただいて結構です」としている。
 * - 住所の郵便番号（1レコード1行、UTF-8 形式）utf_ken_all.zip。長い町域も1行（Shift_JIS の KEN_ALL.CSV は複数の行に分ける）
 * - 事業所の個別郵便番号 jigyosyo.zip（Shift_JIS）
 *
 * 1つの番号の候補は [都道府県, 市区町村, 番地の欄へ入れる値, 建物名の欄へ入れる値（あるときだけ）]。
 * 町域の注記・範囲や並びの町域・括弧の中（丁目の範囲・「次のビルを除く」など）は入れない。
 * 事業所の名前は持たず、事業所の番地は頭の番号だけを入れる（後ろに建物名や、ほかの会社の名前が書いてある）。私書箱は入れない
 *
 * @phpstan-type Candidate list<string>
 * @phpstan-type Entries array<string, list<list<string>>>
 */
final class PostalCodeData
{
    /** 住所の郵便番号の列の数（日本郵便の説明の順） */
    public const ADDRESS_COLUMNS = 15;

    /** 事業所の個別郵便番号の列の数 */
    public const OFFICE_COLUMNS = 13;

    private const FLOOR = '/（(地階・階層不明|[0-9０-９]+階)）$/u';

    /** 郵便番号の形（日本郵便のデータは半角の7桁。そろえずに確かめる） */
    private const CODE = '/\A[0-9]{7}\z/';

    /**
     * 住所の郵便番号（UTF-8 の1行1レコード）の文から、番号 → 候補の一覧
     *
     * @return array<string, list<list<string>>>
     */
    public static function addressEntries(string $text): array
    {
        $rows = array_map(
            fn (array $r, int $i) => ['code' => self::code($r[2], $i + 1), 'pref' => $r[6], 'city' => $r[7], 'town' => $r[8]],
            $all = self::rows(self::utf8($text), self::ADDRESS_COLUMNS),
            array_keys($all),
        );

        // 高層ビルの階ごとの番号の町名は、同じ市区町村の「（次のビルを除く）」の町名から分かる
        $bases = [];
        foreach ($rows as $r) {
            if (str_ends_with($r['town'], '（次のビルを除く）')) {
                $bases[$r['pref'].$r['city']][] = self::stripParens($r['town']);
            }
        }

        $map = [];
        foreach ($rows as ['code' => $code, 'pref' => $pref, 'city' => $city, 'town' => $town]) {
            if (self::isNote($town, $city)) {
                self::add($map, $code, [$pref, $city, '']);

                continue;
            }
            $name = self::stripParens($town);
            $floor = preg_match(self::FLOOR, $town, $m) ? $m[1] : null;
            $base = $floor === null ? null : self::longestBase($bases[$pref.$city] ?? [], $name);
            if ($base !== null) {
                $building = mb_substr($name, mb_strlen($base)).($floor === '地階・階層不明' ? '' : ' '.$floor);
                self::add($map, $code, [$pref, $city, self::halfWidth($base), self::halfWidth($building)]);
            } else {
                self::add($map, $code, [$pref, $city, self::halfWidth($name)]);
            }
        }

        return $map;
    }

    /**
     * 事業所の個別郵便番号（Shift_JIS のバイト列）から、番号 → 候補の一覧。私書箱（種別「1」）は郵便局の所在地なので入れない。
     * 番地の欄へは町名と番地の頭の番号を入れ、後ろの建物名・会社名（ほかの会社の名前が入っていることがある）は入れない
     *
     * @return array<string, list<list<string>>>
     */
    public static function officeEntries(string $shiftJis): array
    {
        $map = [];
        foreach (self::rows(self::shiftJis($shiftJis), self::OFFICE_COLUMNS) as $i => $r) {
            [, , , $pref, $city, $town, $detail, $code, , , $kind] = $r;
            $code = self::code($code, $i + 1);
            if ($kind !== '0') {
                continue;
            }
            self::add($map, $code, [$pref, $city, self::halfWidth((self::isNote($town, $city) ? '' : self::stripParens($town)).self::leadingNumber($detail))]);
        }

        return $map;
    }

    /**
     * 住所と事業所の一覧を合わせる（同じ番号は先に渡した一覧の候補を先に。同じ候補は1つにする）
     *
     * @param  array<string, list<list<string>>>  ...$maps
     * @return array<string, list<list<string>>>
     */
    public static function merge(array ...$maps): array
    {
        $merged = [];
        foreach ($maps as $map) {
            foreach ($map as $code => $candidates) {
                foreach ($candidates as $candidate) {
                    self::add($merged, (string) $code, $candidate);
                }
            }
        }

        return $merged;
    }

    /**
     * 上3桁ごとのファイル（名前 → 中身）。1行に1つの番号を番号の順で書き、作り直したときの差分を変わった番号の行だけにする
     *
     * @param  array<string, list<list<string>>>  $map
     * @return array<string, string>
     */
    public static function shardFiles(array $map): array
    {
        $codes = array_map('strval', array_keys($map));
        sort($codes, SORT_STRING);

        $groups = [];
        foreach ($codes as $code) {
            // ファイルの名前は、7桁の数字に確かめた番号の上3桁だけから作る（読む所でも確かめている。外のデータを道に入れない）
            if (! preg_match(self::CODE, $code)) {
                throw new PostalCodesException('番号の形が7桁の数字ではありません');
            }
            $groups[substr($code, 0, 3).'.json'][] = self::json($code).':'.self::json($map[$code]);
        }
        ksort($groups, SORT_STRING);

        $files = [];
        foreach ($groups as $shard => $lines) {
            $files[$shard] = "{\n".implode(",\n", $lines)."\n}\n";
        }

        return $files;
    }

    /**
     * 置いたデータの指紋（ファイルの名前の順に「名前 NUL 中身 NUL」を並べた SHA-256）
     *
     * @param  array<string, string>  $files
     */
    public static function fingerprint(array $files): string
    {
        $names = array_map('strval', array_keys($files));
        sort($names, SORT_STRING);

        $hash = hash_init('sha256');
        foreach ($names as $name) {
            hash_update($hash, $name."\x00".$files[$name]."\x00");
        }

        return hash_final($hash);
    }

    /** JSON の1つの値（日本語と / はそのまま。ファイルを読みやすく、差分を小さくする） */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** 全角の英数字とハイフンを半角にする（番地の欄の例「1-1-1」とそろえる） */
    public static function halfWidth(string $text): string
    {
        $text = (string) preg_replace_callback('/[０-９Ａ-Ｚａ-ｚ]/u', fn (array $m) => mb_chr(mb_ord($m[0]) - 0xFEE0), $text);

        return (string) preg_replace('/[－−‐]/u', '-', $text);
    }

    /**
     * 行を列に分ける。列の数が日本郵便の説明と違えば止める（形が変わったデータを黙って読み違えない）
     *
     * @return list<list<string>>
     */
    private static function rows(string $text, int $columns): array
    {
        $rows = [];
        $number = 0;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $number++;
            $row = self::csvLine($line);
            if (count($row) !== $columns) {
                throw new PostalCodesException("{$number}行目の列の数が ".count($row)." です（{$columns} のはず）。日本郵便のデータの形を確かめてください");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * CSV の1行（引用符で囲んだ値の中のカンマ・二重の引用符も読む）
     *
     * @return list<string>
     */
    private static function csvLine(string $line): array
    {
        $cells = [];
        $cell = '';
        $quoted = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $c = $line[$i];
            if ($quoted) {
                if ($c === '"' && ($line[$i + 1] ?? '') === '"') {
                    $cell .= '"';
                    $i++;
                } elseif ($c === '"') {
                    $quoted = false;
                } else {
                    $cell .= $c;
                }
            } elseif ($c === '"') {
                $quoted = true;
            } elseif ($c === ',') {
                $cells[] = $cell;
                $cell = '';
            } else {
                $cell .= $c;
            }
        }
        $cells[] = $cell;

        return $cells;
    }

    /** UTF-8 として読む。壊れた UTF-8 は止める。先頭の BOM は落とす */
    private static function utf8(string $bytes): string
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new PostalCodesException('住所の郵便番号のデータを UTF-8 として読めません');
        }

        return str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
    }

    /** Shift_JIS（日本郵便は CP932 の字も使う）として読む。読めない字があれば止める */
    private static function shiftJis(string $bytes): string
    {
        if (! mb_check_encoding($bytes, 'CP932')) {
            throw new PostalCodesException('事業所の個別郵便番号のデータを Shift_JIS として読めません');
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'CP932');
    }

    /** 番号が7桁の数字でなければ止める（ファイルの名前に使うので、外のデータを信じない）。文には行の番号だけを書く */
    private static function code(string $code, int $line): string
    {
        if (! preg_match(self::CODE, $code)) {
            throw new PostalCodesException("{$line}行目の郵便番号が7桁の数字ではありません。日本郵便のデータの形を確かめてください");
        }

        return $code;
    }

    private static function stripParens(string $town): string
    {
        return (string) preg_replace('/（[^）]*）/u', '', $town);
    }

    /**
     * 町域の注記（1つの町名を表さない文）。「一円」は村の全域を表す注記のときだけで、「一円」という町名は残す。
     * 範囲（～）や並び（、）で書いた町域（「種市第８地割～第１４地割」）も1つの町名でないので入れない
     */
    private static function isNote(string $town, string $city): bool
    {
        $stripped = self::stripParens($town);

        return $town === '以下に掲載がない場合'
            // 「境町の次に番地がくる場合」「小菅村の次に664番地以降がくる場合」。決まった文を並べず、注記の形で見る
            || preg_match('/の次に.*がくる場合$/u', $stripped) === 1
            || (mb_strlen($town) > 2 && str_ends_with($town, '一円') && str_ends_with($city, mb_substr($town, 0, -2)))
            || preg_match('/[〜～、]/u', $stripped) === 1;
    }

    /** 事業所の小字・番地のうち、数字で始まる頭の番号（数字・丁目・番地・番・号・地割・ハイフン）だけ。「番外地」のように数字で始まらなければ空 */
    private static function leadingNumber(string $detail): string
    {
        if (! preg_match('/^[0-9０-９]+(?:[0-9０-９]+|丁目|番地|地割|番|号|[－\-])*/u', $detail, $m)) {
            return '';
        }

        return (string) preg_replace('/[－\-]+$/u', '', $m[0]);
    }

    /**
     * @param  list<string>  $bases
     */
    private static function longestBase(array $bases, string $name): ?string
    {
        $found = null;
        foreach ($bases as $base) {
            if (str_starts_with($name, $base) && strlen($name) > strlen($base) && ($found === null || mb_strlen($base) > mb_strlen($found))) {
                $found = $base;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, list<list<string>>>  $map
     * @param  list<string>  $candidate
     */
    private static function add(array &$map, string $code, array $candidate): void
    {
        if (! in_array($candidate, $map[$code] ?? [], true)) {
            $map[$code][] = $candidate;
        }
    }
}
