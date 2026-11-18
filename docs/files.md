# ファイル名とファイルの中身

名前空間 `Miraqceed\Foundation\Files`。**呼んだときだけ働く**（設定・表・依存はない）。

- `FileName`：利用者が書いた名前・上げたファイルの名前を、ダウンロードの名前・表示・DB の列に入れる形にする
- `FileSignature`：先頭のバイトで、ファイルの種類を見分ける
- `ImageMetadata`：写真の位置の情報（EXIF の GPS など）を、画像を作り直さずに消す（向きは残す）

上げたファイルの流れ全体（保存・検査・配り方）は `docs/uploads.md`。

## ファイル名 `FileName`

### ダウンロードの名前

```php
use Miraqceed\Foundation\Files\FileName;

// 利用者が書いた名前を、ファイルの名前に入れる
$name = FileName::name($campaign->name.'_一覧', 'csv');

return response()->streamDownload($write, $name, [
    'Content-Disposition' => FileName::disposition($name, fallback: 'campaign-'.$campaign->id),
]);
```

- `part($name, 200)`：名前の一部。`\ / : * ? " < > |`・制御の字を `_` にし、前後の空白を除き、200バイトまでに**書記素（見た目の1文字）の切れ目**で切る（日本語・ZWJ でつないだ絵文字・結合記号の途中で切らない）。壊れた UTF-8 は直す
- `name($base, $extension, 200)`：拡張子を残して全体を200バイトに収める。空・`.` だけなら `file`。Windows の予約の名前（`CON`・`NUL`・`COM1` など）は前に `_` を付ける。終わりの `.`・空白を除く
- `disposition($name, $fallback = 'download', $inline = false)`：`Content-Disposition` の値。`filename`（ASCII の代わりの名前）と `filename*`（UTF-8 の名前。RFC 6266・5987）を付ける
- `ascii($name, $fallback)`：ASCII の名前。ASCII で書ける字は残し、ほかは `_`。英数字が残らない（`__.csv` のような）ときは、`$fallback` に拡張子を付ける。ZIP の中の名前にも使う

**200バイトにする理由**：ext4・APFS のファイル名は255バイトまでで、日本語は1文字3バイト。アプリが足す接頭辞・接尾辞・拡張子の分を残す。DB の 255字の列にも収まる。

**`/` を含む名前を、そのまま Laravel の `response()->download()`・`streamDownload()` に渡さない。** Symfony の `HeaderUtils::makeDisposition()` が例外を投げ、500 になる。`FileName::name()` か `disposition()` を通す。

**ZIP の中の名前**：日本語の名前を UTF-8 の印なしで書くと、古い展開の道具で化ける。`FileName::ascii()` の名前か、番号の名前（`0001.png`）にし、日本語の名前は一緒に入れる CSV に書く。

### 上げたファイルの名前

```php
$upload->original_name = FileName::forUpload($request->file('document')->getClientOriginalName());
```

- 道（`/`・`\`）を除いた最後の部分だけ（`../../etc/passwd` → `passwd`）
- 壊れた UTF-8 を直し、**NFC にそろえる**（macOS から上げた名前は、濁点が分かれた形のことがある）
- 拡張子を残して200バイトに収める（長い名前が列を超えて 500 にならない）
- **表示と DB の列だけに使う。保存のキー（ストレージの道）には使わない**：保存はアプリが作る名前（ULID など）にする

## 中身の確かめ `FileSignature`

```php
use Miraqceed\Foundation\Files\FileSignature;

$stream = Storage::disk('s3')->readStream($key);
$type = FileSignature::fromStream($stream); // 先頭16バイトだけ読む。分からなければ null
fclose($stream);

if ($type === null || $type !== $declaredType) {
    // 断る（名前を変えたファイル・受けない種類）
}
```

- 見分けるのは、受けてよい種類の許す一覧（`TYPES`）：PNG・JPEG・GIF・WebP・PDF。SVG・HTML・Office・実行ファイルは、どれも `null`
- `detect($head)`・`matches($mimeType, $head)`・`fromFile($path)`・`fromStream($stream)`（ストリームは閉じない）
- **Laravel の `UploadedFile` を通る上げ方では、検証の規則を使う**（下）。これは、署名つきの URL で直に上げたファイルなど、ストレージから読んで確かめるとき向け

### ふつうの上げ方の規則

```php
$request->validate([
    'photo' => ['required', 'file', 'max:10240', 'image', 'extensions:jpg,jpeg,png,webp'],
    'document' => ['required', 'file', 'max:10240', 'mimes:pdf', 'extensions:pdf'],
]);
```

- `mimes`・`image` は、中身から種類を当てる（PHP の fileinfo）。名前を変えた HTML は通らない。fileinfo の拡張がないと、いつも通らない（断る側）
- `extensions` は、利用者の名前の拡張子だけを見る。`mimes` と組で使う
- **`image` は SVG を通さない**（Laravel 12 から）。`image:allow_svg`・`mimes:svg` を書かない。SVG は中にスクリプトを書け、同じオリジンで開くと XSS になる。どうしても受けるなら、別のオリジンから、いつも `attachment` で配る

## 位置の情報を消す `ImageMetadata`

```php
use Miraqceed\Foundation\Files\ImageMetadata;

$clean = ImageMetadata::strip('image/jpeg', $bytes); // 壊れた画像は InvalidArgumentException
Storage::put($key, $clean);
```

- JPEG・PNG・WebP。ほかの種類はそのまま返す
- **画像を作り直さない**：器だけを書き直し、画素のデータはバイトのまま写す。画質・色の情報（ICC）は変わらない
- 消すもの
  - JPEG：残すのは APP0（JFIF）・`ICC_PROFILE` の APP2・APP14（Adobe）だけ。EXIF・XMP（APP1）・MPF などのほかの APP2・IPTC（APP13）・コメントを消す。**最初の EOI より後ろを捨てる**（スマートフォンの写真は、後ろに2枚目の画像をつなぎ、そこにも EXIF を持つことがある）
  - PNG：`eXIf`・`tEXt`・`zTXt`・`iTXt`・`tIME`。IEND より後ろを捨てる
  - WebP：`EXIF`・`XMP `。RIFF の大きさより後ろを捨てる
- **向き（EXIF の Orientation）は残す**：スマートフォンの写真は画素を回さず、向きで回して見せる（ブラウザは既定で従う）。消すと縦の写真が横を向くので、向きだけを持つ小さな EXIF に書き直す。向きが 1（そのまま）なら入れない。WebP は、VP8X の塊を持つ形のときだけ残せる
- 壊れた画像（塊の長さが合わない・終わりがない）は `InvalidArgumentException`。消せないまま配らない。例外の記録に、ファイルの名前・中身を入れない
- 全体を文字列で持つ。上げる大きさの上限（`max:`）と組で使う
- HEIC・AVIF は扱わない（Laravel の `image` は通すので、受けるなら別に作り直すか、`extensions` で断る）
