# 予約メールドメインの分類

`Miraqceed\Foundation\Mail\ReservedEmailDomains::isReserved(string $address): bool` は、単一のbare email addressから取り出したdomainが、対象の予約名かを分類します。設定なしで明示的に呼び出せます。DNS・HTTP・DB・Redisは使いません。

```php
use Miraqceed\Foundation\Mail\ReservedEmailDomains;

ReservedEmailDomains::isReserved('person@example.com');       // true
ReservedEmailDomains::isReserved('person@news.example.org.'); // true
ReservedEmailDomains::isReserved('person@notexample.com');    // false
```

## 対象の名前

[RFC 2606](https://www.rfc-editor.org/rfc/rfc2606.html)と[RFC 6761](https://www.rfc-editor.org/rfc/rfc6761.html)に記載された、次の名前を対象にします。

- TLD：`invalid`、`test`、`example`、`localhost`。これらの下の名前も対象です。
- Domain：`example.com`、`example.net`、`example.org`。これらのsubdomainも対象です。

一致はラベルの境界で判定します。`notexample.com`や`example.com.other.tld`は対象ではありません。IANAのspecial-use nameをすべて網羅する一覧ではありません。

## 正規化と入力の境界

最後のASCII `@` より後ろをdomainとして取り出し、PHP標準のtrim、ASCII小文字化、末尾ASCIIドットの除去を行います。末尾ドットは複数でも除き、TLDとdomainの両方に同じ正規化を使います。

| 入力 | 結果 |
| --- | --- |
| `person@EXAMPLE.COM` | true |
| `person@example.com.` | true |
| `person@one.example.org...` | true |
| `person@example.invalid.` | true |
| `example.com`（ASCII @なし） | false |
| `person@`（domainなし） | false |
| `@example.com` | true |
| `person@@example.com` | true |

**メール形式の検証ではありません。** ローカル部やラベル全体の形式を検証しないため、最後の2例も予約domainとして分類します。利用者入力はアプリ側で形式を検証し、単一のbare addressへ整理してから渡してください。

表示名付きアドレス、URL、複数宛先リストの解析はしません。全角の記号やIDNを変換せず、Unicode空白を一律に削除することもしません。そのような入力にASCII `@` があれば末尾部分が偶然一致する場合もありますが、パース成功・失敗の意味には使えません。

## 返り値と送信の方針

`false`は「対象の予約名に一致しない」という意味だけです。形式が正しい・実在する・送信できることを保証しません。`true`も配達不能を証明するものではなく、localhost等はローカルで特別に扱われることがあります。

パッケージはメールの宛先を自動的に除外せず、全メールに適用するhookも登録しません。送信を止める、入力を断る、試験用経路で扱う等の判断はアプリが明示的に行います。既存の宛先判定を置き換える場合は、末尾ドット等の正規化と既存の送信方針の差を確認してください。
