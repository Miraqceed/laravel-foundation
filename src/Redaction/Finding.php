<?php

namespace Miraqceed\Foundation\Redaction;

/**
 * 伏せたものの1つ。種類と場所だけを持ち、見つけた文字列そのものは持たない（ここから漏れないように）
 */
final readonly class Finding
{
    /**
     * @param  string  $kind  種類（email など。足した形はアプリが付けた名前）
     * @param  string  $path  入れ子の配列での点つなぎの場所（例 evidence.refs.0）。文のときは空。場所にも形を当ててから入れる
     */
    public function __construct(public string $kind, public string $path) {}
}
