<?php

namespace Miraqceed\Foundation\Files;

use Normalizer;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * 利用者が書いた名前・上げたファイルの名前を、ダウンロードの名前・表示・DB の列に入れる形にする。呼んだときだけ働く。
 *
 * - ext4・APFS のファイル名は255バイトまでで、日本語は1文字3バイト。アプリが足す接頭辞・接尾辞・拡張子の分を残し、200バイトにする
 * - バイトで切ると、文字・ZWJ でつないだ絵文字・文字と結合記号の途中で切れる。書記素（見た目の1文字）の切れ目で切る
 * - Windows で使えない字（\ / : * ? " < > |）・制御の字は _ にする。/ と \ は、Symfony の makeDisposition() が例外を投げる（500 になる）
 * - 保存のキー（ストレージの道）には使わない。保存はアプリが作る名前（ULID など）で
 */
final class FileName
{
    public const MAX_BYTES = 200;

    /** Windows が保存できない名前（拡張子があっても） */
    private const RESERVED = '/\A(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/i';

    /** 拡張子とみなす形（英数字10字まで） */
    private const EXTENSION = '/\A[A-Za-z0-9]{1,10}\z/';

    /**
     * 名前の一部（利用者が書いた名前を、ダウンロードの名前に入れるとき）。使えない字を _ にし、$maxBytes までに書記素の切れ目で切る
     */
    public static function part(string $name, int $maxBytes = self::MAX_BYTES): string
    {
        $safe = trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F\x7F]#u', '_', mb_scrub($name, 'UTF-8')));

        return self::cut($safe, max(0, $maxBytes));
    }

    /**
     * 拡張子を残して、全体を $maxBytes に収める。名前が空・. だけなら file。Windows の予約の名前（CON など）は前に _ を付ける。
     * 終わりの . と空白を除く（Windows は保存できない）
     */
    public static function name(string $base, string $extension, int $maxBytes = self::MAX_BYTES): string
    {
        $extension = trim(self::part($extension, 20), ' .');
        $suffix = $extension === '' ? '' : '.'.$extension;

        $base = rtrim(self::part($base, max(1, $maxBytes - strlen($suffix))), ' .');
        if (trim($base, ' .') === '') {
            $base = 'file';
        }
        if (preg_match(self::RESERVED, $base) === 1) {
            $base = '_'.$base;
        }

        return $base.$suffix;
    }

    /**
     * 上げたファイルの名前を、表示と DB の列に入れる形にする。道（/・\）を除いた最後の部分だけ、壊れた UTF-8 を直し、
     * NFC にそろえ（macOS の名前は濁点が分かれた NFD のことがある）、拡張子を残して収める
     */
    public static function forUpload(string $original, int $maxBytes = self::MAX_BYTES): string
    {
        $name = mb_scrub($original, 'UTF-8');
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $name = $normalized;
        }

        $parts = preg_split('#[\\\\/]#u', $name) ?: [''];
        [$base, $extension] = self::split((string) end($parts));

        return self::name($base, $extension, $maxBytes);
    }

    /**
     * ASCII の名前（ヘッダーの代わりの名前・ZIP の中の名前）。ASCII で書ける字は残し、ほかは _。
     * 残りが意味のない名前（英数字がない。__.csv など）なら、$fallback に拡張子を付けて使う
     */
    public static function ascii(string $name, string $fallback): string
    {
        [$base, $extension] = self::split(self::part($name));
        // 1文字を1つの _ にする（u：日本語の1文字が3つの _ にならない。part() を通したので UTF-8 として正しい）
        $clean = fn (string $text): string => (string) preg_replace('/[^\x20-\x7E]|[%"\\\\\/:*?<>|]/u', '_', $text);

        $extension = $clean($extension);
        $base = trim($clean($base));
        if (preg_match('/[A-Za-z0-9]/', $base) !== 1) {
            $base = trim($clean(self::part($fallback)));
            if (preg_match('/[A-Za-z0-9]/', $base) !== 1) {
                $base = 'download';
            }
        }

        return self::name($base, $extension);
    }

    /**
     * Content-Disposition の値。名前を収め（/ で例外にならない）、ASCII の代わりの名前（filename）と UTF-8 の名前（filename*）を付ける
     */
    public static function disposition(string $name, string $fallback = 'download', bool $inline = false): string
    {
        [$base, $extension] = self::split(self::part($name, PHP_INT_MAX));
        $safe = self::name($base, $extension);

        return HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $safe,
            self::ascii($safe, $fallback),
        );
    }

    /**
     * 名前を、拡張子の前と拡張子に分ける。拡張子は最後の . の後ろが英数字10字までのときだけ
     *
     * @return array{string, string}
     */
    private static function split(string $name): array
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return [$name, ''];
        }

        $extension = substr($name, $dot + 1);
        if (preg_match(self::EXTENSION, $extension) !== 1) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), $extension];
    }

    /** $maxBytes までに、書記素（\X）の切れ目で切る */
    private static function cut(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $cut = '';
        preg_match_all('/\X/u', $text, $graphemes);
        foreach ($graphemes[0] as $grapheme) {
            if (strlen($cut) + strlen($grapheme) > $maxBytes) {
                break;
            }
            $cut .= $grapheme;
        }

        return rtrim($cut);
    }
}
