# 文の中の秘密を伏せる

名前空間 `Miraqceed\Foundation\Redaction`。呼んだときだけ働く（設定なしで使える）。DB・キャッシュ・常駐のプロセスを使わない。

**漏れを無くすとは約束しない。** 形で見つけられるもの（鍵・トークン・パスワード・メールアドレス・電話番号）だけを伏せる。人の名前・顧客の名前・形の決まっていない秘密は見つけられない。伏せた後の文も、秘密が残りうるものとして扱う。

## いつ使うか

利用者が貼った文・AI への問い・外のコマンドや API の誤りの文を、**ログ・DB・外の AI に残す前**。

- 利用者は、問い合わせの本文や AI への問いに `.env` の行・接続文字列・トークンをそのまま貼ることがある
- 外の誤りの文（コマンドの標準エラー・API の答えの `message`）は、こちらが送った文を写していることがある。記録する前に伏せる

正規表現を自前で書かない。長い行で PCRE の上限に掛かる・日本語の隣の鍵を逃す・切れ目に欠片が残る、を踏みやすい。

## 使い方

```php
use Miraqceed\Foundation\Redaction\Redactor;

$redactor = app(Redactor::class); // config('foundation.redaction') から作る

// 1つの文
$result = $redactor->text('DB_PASSWORD=Fake1234Pass を貼った');
$result->value;     // 'DB_PASSWORD=[REDACTED] を貼った'
$result->findings;  // [Finding(kind: 'assignment', path: '')]

// 入れ子の配列（文字列の値だけ。キー・数・真偽・null はそのまま）
$result = $redactor->values(['case' => ['body' => '連絡は taro@example.com へ']]);
$result->value;     // ['case' => ['body' => '連絡は [REDACTED] へ']]
$result->findings;  // [Finding(kind: 'email', path: 'case.body')]

// 字数で切って残す（伏せてから切る。出力はいつも 500 字以下）
$log = $redactor->truncate($stderr, 500)->value;
```

- 見つけたもの（`Finding`）は種類と場所だけを持つ。見つけた文字列は持たない。場所にも形を当ててから入れる
- 種類：`private_key`・`aws_key`・`github_token`・`api_key`（`sk-`）・`slack_token`・`jwt`・`assignment`（`password=…`・`"token": "…"` などの値。引用符の中は閉じ引用符までのどの字も読む。8字未満は伏せない）・`url_password`（`postgres://user:…@host` のパスワード）・`email`・`phone`（日本の電話番号。ハイフン2つ）

### 壊れた文字を含む文

壊れた UTF-8 の文は、伏せずに `UnreadableText` を投げる（確かめられない文をそのまま返さない）。例外の文に文の中身は入らない。
外のコマンドの出力など、壊れた文も残したい口では、直してから渡す：

```php
$log = $redactor->text(mb_scrub($output, 'UTF-8'))->value;
```

### 設定

```php
// config/foundation.php
'redaction' => [
    'marker' => '[REDACTED]',          // 伏せた所に入れる印（3字以上で、英数字でない字を含む）
    'kinds' => null,                   // 使う組み込みの種類。null は全部。例：メールアドレスを残すなら email を外した並び
    'patterns' => [                    // アプリが足す形（種類の名前 => 正規表現）
        'myapp_token' => '/(?<![A-Za-z0-9])myapp_[A-Za-z0-9]{32}(?![A-Za-z0-9])/',
    ],
],
```

- 足した形は、組み込みの細かい種類（`private_key`〜`jwt`）の後、`assignment` の前に当てる。`MYAPP_TOKEN=myapp_…` は `myapp_token` として数える
- 知らない種類・作れない正規表現・組み込みと同じ名前・印に当たる形と印・短いか英数字だけの印・配列でない `foundation.redaction` は、作るときに `InvalidRedactionConfig` になる（黙って既定に寄せない）
- 伏せた後の文をもう一度伏せても、文は変わらず、見つけたものは増えない。足した形が、印の前後の字と合わせて当たる書き方（例 `token:\S+`）だと、この決まりが崩れる。足す形は秘密そのものだけに当てる

## してはいけないこと

- **字数で切ってから伏せない。** 切れ目で秘密が欠片になり、形に合わずに残る。`truncate()` を使う
- **知らない形の入れ子をそのまま渡さない。** キーは伏せないので、キーに入った秘密は残る。利用者が送った JSON などは、知っている鍵だけに写してから渡す
- 見つけたもの・例外の文に、元の文を足して記録しない
- 自前の正規表現で値の形を確かめるときは、`$` に `D` を付けるか `\z` を使う（`$` は後ろの改行1つの前でも合う）
- `trim()` の字の組に日本語の字（`「」` など）を入れない（字の組はバイトの集まりとして扱われ、日本語の文を壊す）

## 限界

- 人の名前・顧客の名前・住所は伏せない。形の決まっていない秘密（短いパスワードだけの行など）・Base64 などで包まれた秘密も見つけられない
- IP アドレスは伏せない（個人の情報として、どこで伏せるかはアプリの方針で決める）
- 配列のキーに入った秘密は残る
- `-----END … PRIVATE KEY-----` のない秘密鍵の欠片（すでに切れた文を受け取ったとき）は伏せない
- 鍵の名前は、`password`・`token` などの語の後に 64字までの続き（`AWS_SECRET_ACCESS_KEY` など）を読む。それより長い名前の値は伏せない
- 接続文字列のパスワードに `/` があると、パスワードとして読めない（`postgres://app:pa/ss@db.example.com/x` は、後ろのメールアドレスの形の所だけを伏せ、`pa/` が残る）
- `truncate()`
  - 荒く切る余白は 8,000字。足した形が、それより長い秘密に当たるなら、欠片が残りうる
  - 手前の秘密が伏せて縮むと、上限の字数に届かないことがある
  - 最後の切れ目に、印の欠片（`[REDA`）や、切れて新しくできた形の欠片が残りうる
