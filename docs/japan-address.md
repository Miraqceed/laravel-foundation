# 住所まわり（日本向け）：郵便番号から住所・電話番号の区切り

名前空間 `Miraqceed\Foundation\Japan`。郵便番号から住所を引く `PostalAddressBook`（外のサービスに問い合わせない）、郵便番号の形をそろえる `PostalCode`、電話番号を市外局番の表で区切る `PhoneNumber`、検証の規則 `Rules\JapanesePhoneNumber`・`Rules\JapanesePostalCode`。呼んだときだけ働く。

- **郵便番号の一覧のデータは、パッケージに入れない。** アプリが `php artisan foundation:postal-codes` で自分の倉庫に作る（日本郵便は毎月更新する。作り直しの差分を PR で見られる）
- **市外局番の表は、パッケージに入れる**（`resources/japan/area-codes.json`、約14KB。変わるのはまれ）

## 郵便番号から住所を引く

```php
use Miraqceed\Foundation\Japan\PostalAddressBook;
use Miraqceed\Foundation\Japan\PostalCode;

PostalCode::format('〒１００ー０００１'); // '100-0001'（7桁でなければ null）

app(PostalAddressBook::class)->lookup('1000001');
// [['prefecture' => '東京都', 'city' => '千代田区', 'street' => '千代田']]
// 高層ビルの階の番号：[['prefecture' => …, 'city' => …, 'street' => '（町名）', 'building' => '（ビルの名前） 1階']]
```

- `lookup()` は候補の配列。1つの番号に町名が複数あれば、すべて返す（画面で選ばせる）。番号の形でない・見つからない・一覧を置いていなければ空
- 入力は `PostalCode::format()` と同じ読み方（「〒」・全角・ハイフンとして読む記号・空白）
- 外のサービスに問い合わせない。利用者の入れた郵便番号を第三者に送らず、外のサービスが止まっても引ける

### 一覧を作る・作り直す

```bash
php artisan foundation:postal-codes            # 日本郵便の2つの ZIP を取り、置き場所に書く
php artisan foundation:postal-codes --check    # 置いた一覧が、今の日本郵便のデータから今の決まりで作ったものと同じか（書かない）
```

- 置き場所：`foundation.japan.postal_codes.path`（既定 `resource_path('postal-codes')`）。上3桁ごとの JSON（`100.json` など）と、元の記録 `source.json`（日本郵便の ZIP の指紋・更新日・番号の数・ファイルの数・データの指紋）。**置き場所は一覧だけのディレクトリにする**（ほかのファイルがあれば、コマンドは書かずに止まる。入れ替えで消さないため）
- 作った一覧はアプリの倉庫に入れる（`.gitignore` に入れない）。**毎月、作り直して PR にする。** 1行に1つの番号を番号の順で書くので、差分は変わった番号の行だけになる
- `--check` は、置いた一覧が手で書き換えられていないか・日本郵便の更新を入れたかを CI や手元で確かめる（違えば終わりの値 1）。日本郵便が中身を変えずに ZIP だけ作り直したときは、そう言って失敗する（`source.json` だけが変わる）
- 古さは `foundation:deploy-check` の `postal_codes` が見る：日本郵便の更新日から3か月を過ぎると `later`（古くても引けるので止めない。`--strict` で失敗）。一覧を置いていなければ `skip`

コマンドは外から取ったものを信じない：

- 取るのは日本郵便の決まった2つの `https://` の URL だけ（設定で替えない）。リダイレクトに従わない。200 のほかは失敗。取る大きさの上限 20MB（名乗る大きさ・受けた大きさが超えれば、受けている途中で止める）。User-Agent はパッケージの名前だけ
- 時間の上限：全体 60秒（受けている途中の進みの知らせと、読み終えた後の両方で見る）。つなぐ時間の上限 10秒は、PHP の cURL で送るとき（ふつうの環境）に効く。cURL がなく PHP のストリームで送る環境では、つなぐ時間は PHP の `default_socket_timeout` に従う
- ZIP は自前の小さな読み手で解く（依存を足さない）：中のファイルはちょうど1つで決まった名前の CSV、名前は比べるだけで道に使わない、圧縮は「なし」と deflate だけ（暗号・ZIP64・分割は失敗）、展開後の上限 200MB、名乗りと違う長さ・CRC-32・ありえない日付は失敗。列の数が違う行・郵便番号が7桁の数字でない行・読めない文字で止める。番号が1つもないデータでも止める
- **途中で失敗したら、置き場所を書き換えない。** すべて読めてから置き場所の隣の一時の所に書き、名前の付け替えで入れ替える
- **番号の数が前の版より 5% を超えて減ったら止める**（日本郵便の側の欠けたデータで、多くの番号を黙って消さない）。確かめて正しければ `--force` で書く
- 失敗の文には、取った URL と理由だけを書く（データの中身は書かない）

### 引く口の例（アプリが書く）

経路・回数の制限・画面の JavaScript はパッケージに入れない。打つたびに引く画面なら、利用者ごとに回数を制限し、応答に「読めた番号」を入れて、画面は今の入力と違う応答（遅れて届いた古い応答）を捨てる。

```php
// routes/web.php
Route::get('/postal-address', function (Request $request, PostalAddressBook $book) {
    $code = PostalCode::format($request->string('postal_code')->toString());

    return response()->json([
        'postal_code' => $code,                       // 読めた番号（画面が今の入力と比べる）
        'candidates' => $code === null ? [] : $book->lookup($code),
    ]);
})->middleware(['auth', 'throttle:postal-address']);

// AppServiceProvider::boot()
RateLimiter::for('postal-address', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
```

- 候補を入れるのは、住所の欄が空か、前に自動で入れたままのときだけにする（利用者が書いた住所を黙って上書きしない）。書いてあれば候補を出し、選ばれたら欄をまとめて入れ替える

### 作る決まり

日本郵便の2つのデータ（住所の郵便番号の UTF-8 の1レコード1行の形 `utf_ken_all.zip`、事業所の個別郵便番号 `jigyosyo.zip`）の癖を、`PostalCodeData` の1か所で吸収する：

- 町域の注記（「以下に掲載がない場合」「〜の次に番地がくる場合」・村名の「一円」）・範囲（〜）・並び（、）は町名なし。「一円」という町名そのものは残す
- 括弧の中（丁目の範囲・「次のビルを除く」など）は入れない
- 高層ビルの階ごとの番号は、同じ市区町村の「（次のビルを除く）」の町名から町名を見つけ、ビルの名前と階を建物名の欄（`building`）へ
- 全角の英数字・ハイフンは半角（番地の欄の例「1-1-1」とそろえる）
- 事業所：私書箱は入れない。番地は頭の番号だけ（後ろの建物名・ほかの会社の名前を入れない）。事業所の名前は持たない
- 1行の形を読むので、KEN_ALL（Shift_JIS）の長い町域の分かれた行をつなぐ処理は要らない

## 電話番号を区切る

```php
use Miraqceed\Foundation\Japan\PhoneNumber;

PhoneNumber::format('０３（１２３４）５６７８'); // '03-1234-5678'
PhoneNumber::format('0429123456');            // '04-2912-3456'（6桁の表で）
PhoneNumber::format('0120988372');            // '0120-988-372'
PhoneNumber::problem('03-12');                // 'shape'（番号の形でない）・'grouping'（区切りが決まらない）・null
```

- 桁の数で推測しない。ハイフン・括弧・空白で3つに区切って入力されたら、その区切りを使う（0120 を「0120-98-8372」と書く会社がある）
- 数字だけなら：11桁は携帯（060・070・080・090）・IP（050）・M2M（020）が 3-4-4、0800 が 4-3-4。0120・0570・0990 は 4-3-3。**固定電話は、0 から市内局番までの6桁で市外局番の桁を決める**（市外局番の表の前方一致。04-29xx と 042-6xx のように、先頭が同じでも6桁で変わる）。加入者番号は4桁
- 区切れない理由を分ける：`shape`（使えない文字・0 から始まる10桁か11桁でない）、`grouping`（形は合うが区切りが決まらない）。断る文言を分ける（区切っても通らない番号に「ハイフンで区切って」と言わない）
- 表は `PhoneNumber` を呼んだときだけ読む
- ログの文の電話番号を伏せる `Redaction\Redactor` の `phone` とは別のもの（あちらは見つけて伏せる、こちらは入力を読んで区切る）

## 検証の規則

```php
use Miraqceed\Foundation\Japan\PhoneNumber;
use Miraqceed\Foundation\Japan\PostalCode;
use Miraqceed\Foundation\Japan\Rules\JapanesePhoneNumber;
use Miraqceed\Foundation\Japan\Rules\JapanesePostalCode;

$request->validate([
    'phone' => ['required', 'string', new JapanesePhoneNumber],
    'postal_code' => ['required', 'string', new JapanesePostalCode],
]);

// 保存する値は区切った形にする（規則は値を変えない）
$user->phone = PhoneNumber::format($request->input('phone'));
$user->postal_code = PostalCode::format($request->input('postal_code'));
```

- 文はパッケージの規則の文（`foundation::validation.japanese_phone_number`・`japanese_phone_number_grouping`・`japanese_postal_code`。日本語と英語）。アプリは `lang/vendor/foundation` で上書きできる（`vendor:publish --tag=foundation-lang`）
- 文字列でない値は通す（型は `string` の規則の仕事）

## 出典

- **郵便番号**：日本郵便株式会社の「郵便番号データ」（住所の郵便番号・事業所の個別郵便番号）。日本郵便は「郵便番号データに限っては日本郵便株式会社は著作権を主張しません。自由に配布していただいて結構です。」としている。パッケージはデータを持たず、アプリが作る
- **市外局番の表**（`resources/japan/area-codes.json`）：**総務省ホームページ「電気通信番号指定状況」（https://www.soumu.go.jp/main_sosiki/joho_tsusin/top/tel_number/number_shitei.html）を加工して作成。** 固定電話の6桁の番号区画ごとの市外局番の桁を、前方一致の表に縮めた。総務省のホームページのコンテンツは「公共データ利用規約（第1.0版）」（CC BY 4.0 互換）による。表の `source` にも同じことを書いている。表を写して配るときも、出典と加工の記載を残す

## 市外局番の表を作り直す（パッケージの保守の人だけ）

`tools/make-area-codes.mjs`（Node）。配布物に入れない（`.gitattributes` の `export-ignore`）。**保守の人の手元だけで動かす。** CI でも試しでも動かさない（試しは作った表を読む）。市外局番が変わったら（まれ）作り直して PR にする。

```bash
node tools/make-area-codes.mjs
```

- 依存を足さない（composer の依存にも `package.json` にも入れない）。.xls を読むため、動かすたびに SheetJS の決まった版の tarball を取り、**決め打ちの SHA-512（integrity）を確かめてから**一時の所に展開して読み込む。違えば止まる。版を上げるときは、版と integrity を一緒に直す PR にする
- npm の `xlsx`（0.18.5 が最後の版）は使わない（知られた脆弱性がある）
- 取るのは決まった `https://` の道だけ（総務省の `www.soumu.go.jp` と SheetJS の配布元）。リダイレクトに従わず、時間と大きさの上限つき

## 限界

- 一覧は日本郵便の公開のデータの範囲（料金受取人払の個別番号は日本郵便が載せていない）。町域の注記の形が日本郵便の側で変わると、作る決まりを直すまで町名なしにならないことがある（列の数・ZIP の形の変化は止まる）
- 一覧が3か月より古くても引ける（`postal_codes` は `later`）。毎月の変わる行は数十なので、多くの番号は引ける
- 電話番号：入力の区切りが形（0 から始まる2〜5桁・1〜4桁・3〜4桁）に合えば、表と合わなくても使う。固定電話の表は総務省の指定状況の範囲（新しい市内局番の指定で6桁の行は増えるが、市外局番の桁が変わるのはまれ）
