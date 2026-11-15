# CSV

名前空間 `Miraqceed\Foundation\Csv`。常に使える（有効にする設定はない）。

## いつ使うか

CSV を書き出す（ダウンロード）・読み込む（取り込み）とき。表計算ソフトの式の守り・Shift_JIS・途中で失敗したファイルの印・大きな表の読み方を、自前で書かない。

## 書き出し

```php
use Miraqceed\Foundation\Csv\CsvResponse;
use Miraqceed\Foundation\Csv\Encoding;
use Miraqceed\Foundation\Csv\Rows;

return CsvResponse::download(
    '企業一覧.csv',
    ['ID', '会社名'],
    // 行は配列を返す。Rows::keyset() は Model を返すので、ここで配列にする
    (function () {
        foreach (Rows::keyset(Company::query(), 'id') as $company) {
            yield [$company->id, $company->name];
        }
    })(),
    Encoding::Utf8Bom, // 既定。Excel 向けに BOM 付き。Shift_JIS が要るなら Encoding::ShiftJis
);
```

- 全体を組み立てずに流す（`StreamedResponse`）。`Content-Type: text/csv`。日本語のファイル名は `filename*` で付く。
- すべてのセルに式の守り（`CellGuard`）が掛かる。`=`・`+`・`-`・`@`・タブ・改行・全角の `＝＋－＠` で始まる値は先頭に `'` が付く（符号付きの数はそのまま）。**自前で `'` を足さない**（二重になる）。
- Shift_JIS（`CP932`）に変換できない文字は `?` になる（行がずれるより読めない方を選ぶ）。
- **途中で失敗したら**、ファイルの最後に `※ 書き出しが途中で失敗しました。このファイルは不完全です。` の行を書き、例外は `report()` する。ステータスは 200 のまま（流し始めた後なので変えられない）。

## 大きな表の読み方（重要）

| 方法 | 使うか |
|---|---|
| `Model::lazy()`・`chunk()` | **使わない。** LIMIT/OFFSET でページを繰るので、読んでいる間に行が増減すると境目の行が重複・欠落する（並びを一意にしても防げない） |
| `Rows::cursor($query, $chunk = 500)` | 1回の問い合わせで読むので**ずれない**。`with()` も 500 行ずつ `loadMissing` でまとめて読む。**ただしメモリーは減らない**：PostgreSQL も MySQL も結果を手元に読み込む（バッファあり）。小〜中の表向き |
| `Rows::keyset($query, 'id', $page = 500)` | **大きな表向き。** `where 列 > 前の最後` でページを繰る。メモリーは1ページ分。読んでいる間に増えた行（列の大きい側）も読む |

`Rows::keyset()` の決まり：

- 渡す列は**一意で並べられる列**（重複があると行を飛ばす）。
- 列は**修飾しない名前**（`id`。`rows_parents.id` は不可。`LogicException`）。`select` から外した列も不可。
- 並びは列の昇順に**置き換わる**。呼び出し側の `orderBy`・`offset`・`limit` は無視される。

## 読み込み

```php
use Miraqceed\Foundation\Csv\CsvReader;
use Miraqceed\Foundation\Csv\SourceEncoding;

$bytes = file_get_contents($path);
$rows = CsvReader::parse($bytes);          // 文字コードを自動判定。list<list<string>>（見出しも1行目）
// 判定が外れるときは利用者に選ばせる：
$rows = CsvReader::parse($bytes, SourceEncoding::ShiftJis);
```

- UTF-8・BOM 付き UTF-8・Shift_JIS（CP932）。`CsvReader::detect($bytes)` は判定できなければ `null`。**`parse()` は、文字コードを渡されず判定もできないとき、UTF-8 として続ける**（読めない並びはそのまま残る）。取り込みの前に `detect()` が `null` なら利用者に文字コードを選ばせる。
- `SourceEncoding::Utf8` を明示しても、先頭に BOM があれば落とす（先頭のセルに BOM が残らない）。
- 空行は落とし、各セルは `trim` する。書き出しが式の印として足した `'` は外す（`CellGuard::unguard`）。
- NUL バイトを含むと `CsvReadException`。
- 行数・大きさの上限は持たない。**アップロードの大きさの上限はアプリが掛ける。**

## してはいけないこと

- `fputcsv`・`fgetcsv` を自前で呼ばない。呼ぶなら**エスケープ文字は `''`**（`\"` で引用符が閉じて次のセルに式を作れる。PHP 8.4 は省くと非推奨の警告）。
- 式の守りを自前で書かない。
- 書き出しに `lazy()`・`chunk()` を使わない。
