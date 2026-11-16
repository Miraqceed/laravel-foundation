# 子プロセスの環境変数

`Miraqceed\Foundation\Support\ChildEnvironment`。アプリが外のコマンド（CLI・rsync・変換の道具など）を子プロセスで動かすとき、子に `.env` の秘密を渡さない。

## なぜ

Symfony Process は、渡した環境に親の環境（`$_ENV` と、`$_SERVER` と名前が重なる `getenv()`）を足して子に渡す。Laravel は `.env` の値を `$_ENV` と `$_SERVER` に入れるので、何もしないと、子は `DB_PASSWORD`・`APP_KEY`・外の API の鍵を受け継ぐ。Laravel の `Process` ファサードも、`env()` で渡した配列を Symfony Process に渡すだけなので、同じく受け継ぐ（ほかの名前を `env()` で渡しても受け継ぐ）。受け継がせないには、名前ごとに `false` を渡す。

## 使い方

```php
use Illuminate\Support\Facades\Process;
use Miraqceed\Foundation\Support\ChildEnvironment;

// 親の名前を全部 false にし、PATH と HOME だけを親の値で渡す。$set の名前は決めた値で渡す
$result = Process::env(ChildEnvironment::only(['PATH', 'HOME'], ['LANG' => 'C.UTF-8']))
    ->run(['some-cli', '--flag']);
```

- `only($names, $set)` は、親の名前を全部 `false` にした配列に、`$names` を親の値で入れ、`$set` で上書きする
- 親に無い名前を `$names` に挙げても、足さない（空の値を作らない）
- Symfony の `new Process($command, null, ChildEnvironment::only([...]))` にも、そのまま渡せる
- 多くのコマンドは `PATH` が要る。CLI によっては `HOME`（設定の置き場）も要る

## 入力を書く子は、動かす前に確かめる

無いコマンド・実行できないコマンドを動かして、その標準入力に書くと、Symfony Process は `sh -c 'exec …'` に回し、すぐ終わった `sh` の標準入力に書いて Broken pipe の notice を起こす（試しでは、notice が結果を汚す）。動かす前に確かめる：

```php
use Symfony\Component\Process\ExecutableFinder;

if ((new ExecutableFinder)->find('some-cli') === null) {
    // 「some-cli がありません」の失敗として扱う（動かさない）
}
```

## してはいけないこと

- 外のコマンドを、`env()` なし（親の環境を全部受け継ぐ形）で動かさない
- 子の誤りの文（標準エラー）を、そのままログ・DB に残さない。秘密を伏せる部品（`docs/redaction.md`）を通す（子がこちらの渡した値を写していることがある）

## 限界

- 子プロセスの中で、子が自分で読むファイル（`~/.config` など）の秘密は防がない
- `$names` に挙げた名前の値は、そのまま子に渡る。秘密の名前を挙げない
