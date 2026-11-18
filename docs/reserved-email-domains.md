# 実在しないメールアドレスへ送らない

名前空間 `Miraqceed\Foundation\Mail`。口は3つ：判定 `ReservedEmailDomains::isReserved()`・入力の検証の規則 `NotReservedEmailDomain`（どちらも設定なしで使える）・送る前の差し込み（既定で無効。`foundation.mail.drop_reserved_recipients`）。

## いつ使うか

規格で実在しないと決まっているドメイン（`example.com`・`.invalid`・`.test` など）のメールアドレスへ、メールを送らせないとき。送っても届かず、送信サーバーに宛先不明の戻り（バウンス）だけが残る。戻りが続くと送信元の評価が下がり、**本物の利用者へのメールまで届きにくくなる**。

当たるのは、アプリ自身が作った宛先が多い：退会した利用者の伏せ字（`deleted-5@anonymized.invalid`）・E2E の使い捨ての利用者・`fake()->safeEmail()` で作った利用者・設定の書き損じ（知らせの宛先を `ops@example.com` のままにした）。利用者が登録の欄に入れた場合は、確認のメールが届かないうえ、そのアドレスが埋まる。

- 利用者が入れる欄には検証の規則を付ける（入れた人に直してもらえる）
- アプリが作る宛先は、送る前の差し込みで止める

**自前で予約の一覧を書かない。** 末尾の点・全角・点のないドメインなど、すり抜けやすい書き方をこの判定がそろえる。

## 何を予約とみなすか

[RFC 2606](https://www.rfc-editor.org/rfc/rfc2606.html)・[RFC 6761](https://www.rfc-editor.org/rfc/rfc6761.html) の名前と、点のないドメイン。一覧は固定（アプリが足す設定はない）。

- トップレベル：`invalid`・`test`・`example`・`localhost`（その下の名前も）
- ドメイン：`example.com`・`example.net`・`example.org`（そのサブドメインも）
- 点のないドメイン（`a@intranet`。インターネットの宛先にならない）

比べる前に、最後の ASCII の `@` より後のドメインの、前後の空白・大文字・末尾の点・全角の英数字・全角のピリオド `．` と句点 `。` `｡` をそろえる。比べるのはラベルの境目だけ（`notexample.com`・`example.com.au`・`invalid.jp` は予約でない）。

```php
use Miraqceed\Foundation\Mail\ReservedEmailDomains;

ReservedEmailDomains::isReserved('taro@example.com');        // true
ReservedEmailDomains::isReserved('taro@news.example.org.');  // true（末尾の点）
ReservedEmailDomains::isReserved('ｔａｒｏ@ｅｘａｍｐｌｅ．ｃｏｍ'); // true（全角）
ReservedEmailDomains::isReserved('taro@intranet');           // true（点のないドメイン）
ReservedEmailDomains::isReserved('taro@notexample.com');     // false
```

**メールアドレスの形の確かめではない。** 1つの素のアドレスを渡す。表示名付きのアドレス・URL・複数の宛先を並べた文字列は解かない。ローカル部・`@` の数・ラベルの形は確かめない（`@example.com`・`a@@example.com` も予約）。全角の `＠` は `@` とみなさない。形は Laravel の `email` の規則で確かめる。

`false` は「予約の名前に当たらない」だけで、形が正しい・実在する・届くことは約束しない。DNS・MX は引かない。設定・DB を読まない。

## 入力の欄

そのアドレスへ後でメールを送る欄（登録・メールアドレスの変更・招待の宛先）に付ける：

```php
use Miraqceed\Foundation\Mail\NotReservedEmailDomain;

'email' => ['required', 'string', 'email', 'max:255', new NotReservedEmailDomain],
```

- 文は「:attributeには、メールを届けられません。普段お使いのメールアドレスを入れてください。」（`en` は英語）。変えるときは `php artisan vendor:publish --tag=foundation-lang` で `lang/vendor/foundation/{ja,en}/validation.php` に写し、鍵 `reserved_email` を書き換える
- 文字列でない値は通す（型は `string` の規則で断る）
- **ログインの欄には付けない。** 登録済みのアドレスでも、「メールアドレスかパスワードが違う」と出すべきため
- この判定は、判定のためだけに全角・句点・大文字をそろえる。**保存する値は整えない。** 全角のまま保存されないよう、入力は `Mail\NormalizeEmailInput` で検証の前にそろえる（`docs/email-input.md`）

## 送る前の差し込み

```php
// config/foundation.php
'mail' => ['drop_reserved_recipients' => true],
```

- 送る直前（`MessageSending`）に、予約の宛先を To・Cc・Bcc から外す。From・Reply-To は触らない
- 実在する宛先と並んでいれば、その人には送る。宛先が1つも残らなければ送信を取りやめ、`Mail::send()`・`Mailable::send()` は `null` を返す
- 外したメールはログに書かない（送ったかは戻り値で分かる）
- 効く範囲：Laravel のメーラーを通るもの全部（`Mail::send()`・`Mail::raw()`・Mailable・キューに入れた Mailable（送るときのワーカーで）・通知の `mail` の経路）
- 聞き手はいつも登録され、送るときに設定を読む（`config()` で切り替えても効く）

### 試しと開発の環境への影響

- **有効にすると、テストの中でも効く。** `array`・`log` のメーラーで `example.*` 宛てのメールを確かめている試しは、メールが送られなくなる。`fake()->safeEmail()` は `example.com`・`example.net`・`example.org` を作る。試しの宛先には、予約でないドメインを使う
- `Mail::fake()` はメーラーを通らないので、影響しない（`Mail::assertSent()` はこれまでどおり数える）
- `Mail::alwaysTo()`（`config('mail.to')`）で開発の環境のメールを `dev@example.com` などへ集めていると、差し込みを有効にした環境ではメールが1通も出ない（`alwaysTo` は差し込みより前に宛先を置き換える）。集める先は予約でないアドレスにする

## 見張りの知らせのメール

`foundation.monitoring.alerts.mail.to` の予約の宛先は、`drop_reserved_recipients` によらず外して送る（`docs/monitoring.md`）。

- 残りが1つもなければ、送れなかったと記録する（送り直しと諦めの流れに乗る）
- 一部を外したときは、残りへ送り、外した宛先を警告のログに1行残す（設定の書き損じに気付くため）

## してはいけないこと

- 予約の一覧や判定を自前で書く（すり抜けを直した判定がある）
- 伏せ字・E2E・試しの宛先に、実在しうるドメインを使う（本当に誰かへ届く）。予約のドメイン（`.invalid` など）を使い、送る前の差し込みを有効にする
- ログインの欄に `NotReservedEmailDomain` を付ける

## 限界

- `Mail::fake()` の下と、Symfony の transport を直に呼んだメールには、差し込みは効かない
- 通知の `mail` の経路で宛先がすべて外れても、Laravel は `NotificationSent` を出す（戻り値は `null`）
- 見張りの知らせのメールで、ほかの `MessageSending` の聞き手が送信を取りやめたときは、送れたと記録される（`sendNow()` の戻り値は、`Mail::fake()` の下でも `null` になるので見ない）
- IP の書き方の宛先（`a@[192.0.2.1]`）・使い捨てのメールのドメイン・MX は見ない
- `.local`・`.internal` などは予約とみなさない（社内のメールで実際に届くことがあるため）
