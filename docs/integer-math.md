# 非負整数を正確に計算する

`Miraqceed\Foundation\Support\UnsignedInteger` は、PHP の整数の上限を超える値も10進文字列として計算します。BCMath・GMP は不要です。設定やコンテナへの登録はなく、static method を呼んで使います。

## 使い方

```php
use Miraqceed\Foundation\Support\UnsignedInteger;

UnsignedInteger::add('99999999999999999999', 1); // '100000000000000000000'
UnsignedInteger::multiply('1234', 5000);        // '6170000'
UnsignedInteger::ceilDivideByPowerOfTen('123456', 3); // '124'
UnsignedInteger::compare('00042', 42);         // 0
```

| method | 結果 |
|---|---|
| `add(left, right): string` | 和 |
| `multiply(left, right): string` | 積 |
| `ceilDivideByPowerOfTen(value, scale): string` | `value / 10^scale` を切り上げた整数 |
| `compare(left, right): int` | 左が小さければ `-1`、等しければ `0`、大きければ `1` |

計算結果は常に `0` または先頭がゼロでない数字列です。比較も数字列を float や native int に変換せず、桁数と各桁で比べます。

## 受け取る値

値に渡せるのは、**実際の非負の PHP int**、または**空でない ASCII の数字列**です。`'00042'` のような先頭のゼロは受け入れ、`'42'` にそろえます。

`1.0` のような float、bool、null、配列、object、resource は受け取りません。負数、符号、空白、小数点、指数表記、全角など ASCII 以外の数字も拒否します。PHP の弱い型変換が有効な呼び出し元から渡しても同じです。不正な引数では `InvalidArgumentException` を投げ、例外文に入力値を埋め込みません。

`scale` は**実際の非負の int**だけを受け取ります。`'3'` や `3.0` は拒否します。`scale = 0` は値の正規化だけを行います。桁数以上なら、ゼロは `'0'`、正数は `'1'` です。`PHP_INT_MAX` のような大きい `scale` でも、その長さのゼロ列や除数を作りません。

## 呼び出す側で決めること

入力の桁数に上限は設けていません。入力の大きさと処理時間の制限はアプリで決めます。足し算・比較・切り上げの処理量は桁数に比例し、掛け算の処理量は両方の桁数の積に比例します。

結果を DB・JSON へ保存するときの範囲や、整数へ変換してよいかもアプリで判断します。大きい値のまま正確さを保つときは文字列で扱います。単位、通貨、小数の扱い、切り上げる桁数の選択は呼び出す側の責任です。

## 試す

```bash
vendor/bin/phpunit tests/Unit/Support/UnsignedIntegerTest.php
```

この単体試験は DB や Laravel の起動を必要としません。
