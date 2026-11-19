<?php

namespace Miraqceed\Foundation\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * 要求の本文の大きさの上限。別名 foundation.body-limit:<バイト>。経路に掛けて経路ごとの上限、全体の先頭に足してサイト全体の上限。
 *
 * Laravel の全体の上限は post_max_size（ValidatePostSize）だけで、経路ごとの上限はない。上限を超えた本文を、回数の制限・検証・コントローラーより前に断る。
 * JSON の本文は、Laravel が要求を作るとき（Request::createFromBase()）に丸ごと解くので、どの中間処理でも「解く前」には断れない。解かせないのは Web サーバーの上限だけ（docs/http.md）。
 * パッケージの起動で、優先の表の回数の制限（ThrottleRequests）の前に入れる（大きな本文を数えてから断らない）。
 *
 * - 名乗った大きさ（Content-Length）が上限を超えれば、本文に触らずに断る
 * - 名乗らない・上限以下に名乗った本文は、実際の長さで確かめる。Request の状態に触れずに測る（後のコントローラーが getContent()・json() を読める）
 * - multipart/form-data は php://input が空なので、受け取ったファイルの大きさとフォームの欄で測る
 * - 断ったら記録し（道・大きさ・上限。本文とクエリ文字列は書かない）、413 の HttpException を投げる（形はアプリの例外の扱い）
 */
final class LimitBodySize
{
    public function handle(Request $request, Closure $next, string $bytes = ''): Response
    {
        $limit = self::limit($bytes);
        if ($limit === null) {
            return $next($request);
        }

        $declared = $request->headers->get('Content-Length');
        $size = is_string($declared) && ctype_digit($declared) ? (int) $declared : null;
        if ($size === null || $size <= $limit) {
            $size = max($size ?? 0, self::measure($request, $limit));
        }

        if ($size > $limit) {
            Log::warning('foundation.body_limit', ['path' => $request->path(), 'bytes' => $size, 'limit' => $limit]);

            throw new HttpException(413, 'Payload Too Large');
        }

        return $next($request);
    }

    /** 上限の引数。書き損じは、本番でない環境では例外、本番では断らずに通して記録する（要求を落とさない） */
    private static function limit(string $bytes): ?int
    {
        if (ctype_digit($bytes) && (int) $bytes > 0) {
            return (int) $bytes;
        }

        if (! app()->environment('production')) {
            throw new InvalidArgumentException('foundation.body-limit の引数は、上限のバイト数（1以上の整数）にしてください');
        }
        Log::warning('foundation.body_limit.invalid', ['limit' => $bytes]);

        return null;
    }

    /**
     * 本文の実際の長さ。上限＋1バイトまでしか数えない。Request の状態（読んだ本文・ストリーム）を変えない
     */
    private static function measure(Request $request, int $limit): int
    {
        if (str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'multipart/form-data')) {
            return self::multipart($request);
        }

        // Request が持つ本文（試しで渡した文字列・前の中間処理が読んだもの・ストリーム）。getContent() を呼ぶと状態が変わるので、中を覗くだけ
        $content = (fn () => $this->content)->call($request);
        if (is_string($content)) {
            return strlen($content);
        }
        if (is_resource($content)) {
            $position = ftell($content);
            rewind($content);
            $length = strlen((string) fread($content, $limit + 1));
            if ($position !== false) {
                fseek($content, $position);
            }

            return $length;
        }

        // まだ読んでいない：php://input を別に開いて読む（PHP 5.6 から何度でも開ける。Request は後で自分で読む）
        try {
            $input = @fopen('php://input', 'rb');
            if ($input === false) {
                return 0;
            }
            $length = strlen((string) stream_get_contents($input, $limit + 1));
            fclose($input);

            return $length;
        } catch (Throwable) {
            return 0;
        }
    }

    /** 受け取ったファイルの大きさの和と、フォームの欄の長さ */
    private static function multipart(Request $request): int
    {
        $size = strlen(http_build_query($request->request->all()));
        $files = $request->files->all();
        array_walk_recursive($files, function (mixed $file) use (&$size) {
            if ($file instanceof UploadedFile) {
                $size += (int) $file->getSize();
            }
        });

        return $size;
    }
}
