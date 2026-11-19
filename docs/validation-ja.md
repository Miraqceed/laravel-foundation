# 日本語の入力の誤りの文と、訳し忘れを見つける試し

- 写す日本語の文 `lang-ja/`：Laravel の規則・認証・パスワードの再設定・ページ送りの文（`validation`・`auth`・`passwords`・`pagination`）を、アプリの `lang/ja` に写す
- 試しの助け `Testing\Translations`：足りない鍵（`missing()`）と、英語の混ざった誤りの文（`assertNoEnglish()`）を見つける

**入れただけでは変わらない。** 日本語の文は、アプリの `lang/ja` に写したときだけ効く。試しの助けは、試しから呼んだときだけ働く。

## なぜ要るか

Laravel が持つ言語ファイルは英語だけで、`php artisan lang:publish` も英語を `lang/en` に写すだけ。`APP_LOCALE=ja` にしても、文がない鍵は予備の言語（`fallback_locale`、既定 `en`）の文になる。

- 規則の文がなければ、欄の名前だけが訳されて「The メールアドレス field is required.」と混ざる
- `lang/ja/validation.php` に使っている規則だけを書くと、後で使い始めた規則の文が英語になる
- 欄の名前がなければ、キーの `_` を空白にしたものが入る（「current passwordを入力してください。」）

## 写す

```bash
php artisan vendor:publish --tag=foundation-lang-ja
```

- アプリの `lang/ja/` に `validation.php`・`auth.php`・`passwords.php`・`pagination.php` を写す。写した後はアプリのファイルで、直して使う。パッケージを上げても、写した文は変わらない
- **既にあるファイルは上書きしない**（`--force` を付ければ上書きする）。自分の `lang/ja/validation.php` があるときは、次のどちらか：
  - 写した後に自分の文を足し直す：自分のファイルを別の名前に除けてから写し、`custom`・`attributes` と直した文を写したファイルに戻す
  - 足りない鍵だけを足す：下の `Translations::missing()` で足りない鍵を出し、`vendor/miraqceed/laravel-foundation/lang-ja/validation.php` から写す
- 自動では読ませない。パッケージが言語の道を足すと、Laravel はアプリの `lang` より後に読み、アプリの訳を上書きするため
- `lang:publish`（英語を `lang/en` に写す）と一緒に使ってよい。英語の文を直すなら `lang/en`、日本語は `lang/ja`
- 鍵は、支える Laravel の版（12・13）の英語の `validation.php` にある鍵をすべて持つ。古い版では使われない鍵があっても害はない
- 言い回しは「:attributeを入力してください。」「:attributeは:max文字以内にしてください。」「:attributeの形が正しくありません。」。文末に「。」を付ける（パッケージ自身の規則の文 `foundation::validation.*` とそろえる）。句点を付けたくなければ、写したファイルを直す

パッケージ自身の規則の文（`foundation::validation.*`。メールアドレス・Turnstile などの規則）は別のファイルで、写さなくても日本語で出る。直すときは `vendor:publish --tag=foundation-lang`（`lang/vendor/foundation` に写す）。

## 欄の名前

写す `validation.php` の `attributes` には、どの画面でも同じ呼び名になる4つだけを置いている：`email`（メールアドレス）・`password`（パスワード）・`password_confirmation`（パスワード（確認））・`current_password`（現在のパスワード）。

- どの画面でも同じ呼び名の欄は、`attributes` に足す
- **画面で呼び名が違う欄は、確かめる所で渡す**。`name`（氏名・会社名・商品名）を `attributes` に置くと、名前を渡し忘れた画面でも「氏名を入力してください。」と出て、間違いに気付けない

```php
$request->validate(['name' => 'required|max:50'], [], ['name' => '会社名']);

// Form Request
public function attributes(): array
{
    return ['name' => '会社名'];
}
```

## 試しで確かめる `Testing\Translations`

本番のコードからは呼ばない。

```php
use Miraqceed\Foundation\Testing\Translations;

public function test_日本語の文がそろっている(): void
{
    $this->assertSame([], Translations::missing('ja'));
}

public function test_パスワードの変更の誤りに英語が混ざらない(): void
{
    $response = $this->actingAs($user)->post('/settings/password', ['current_password' => '']);

    Translations::assertNoEnglish($response);
}
```

### `missing($locale = 'ja', $groups = ['validation', 'auth', 'passwords', 'pagination'], $namespace = null)`

基準の英語（Laravel の `lang/en` ＋ アプリの `lang/en`）にあって、アプリの `lang/{$locale}` にない鍵を返す（`validation.required`・`validation.max.string` の形。入れ子の鍵まで）。

- `lang:publish` で写した `lang/en` に自分の規則の文を足したら、その鍵も基準に入る（日本語を足し忘れると返る）
- `custom`（欄ごとの文）と `attributes`（欄の名前）は比べない。アプリが自分の欄に合わせて書くもので、英語の例と鍵がそろわない。欄の名前の置き忘れは、`assertNoEnglish()` で画面ごとに見つける
- Laravel を上げて規則が増えると、新しい鍵が返る。パッケージの `lang-ja/validation.php` から写すか、自分で書く
- `$namespace` を渡すと、その名前空間の文の en との差を返す（`missing('ja', ['validation'], 'foundation')` はパッケージ自身の規則の文。`lang/vendor/foundation` の上書きも入れて比べる）

### `assertNoEnglish($errors, $allow = [])`

誤りの文に、英語の語がないことを確かめる。英語の語は「前後が英字でない、小文字の英字2字以上の続き」。

- 受けるもの：`TestResponse`（セッションの誤りのすべての袋と、JSON の `errors`）・`MessageBag`・文の配列（入れ子でもよい）
- 失敗の文に、英語の混ざった文と、見つけた語を出す
- 大文字で始まる語（`URL`・`IPv4`・`Base64`・`JSON`）は数えない。置き換わらなかった印（`:attribute`）は数える
- ファイルの拡張子（`jpg`・`png`・`pdf`・`csv` など。`mimes`・`extensions` の `:values`）は既定で数えない。ほかに文に入る値（`starts_with` の値・`mimetypes` の `image/jpeg` など）は `$allow` で渡す：`Translations::assertNoEnglish($response, ['image'])`
- 文が1つもなければ失敗する（誤りが出なかったのに通ると、確かめたつもりで何も見ていない）

## 限界

- 日本語だけ。ほかの言語の文は持たない
- 画面の文言（`lang/ja.json`）は持たない（アプリの文言はアプリが書く）
- `assertNoEnglish()` は、試しで実際に出した誤りの文しか見ない。画面ごとに、欄を空にして送る試しを書く
