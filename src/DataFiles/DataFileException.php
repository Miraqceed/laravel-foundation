<?php

namespace Miraqceed\Foundation\DataFiles;

use RuntimeException;

/**
 * データのファイルを入れ替えられない（取れない・確かめで断った・入れ替えられない）。
 * 文には種類の名前と理由だけを書く。URL は問い（? から後）と userinfo を落とし、見出しの値・データの中身を書かない。元の例外を previous にしない
 */
class DataFileException extends RuntimeException {}
