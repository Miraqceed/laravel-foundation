<?php

namespace Miraqceed\Foundation\Redaction;

/**
 * 伏せる前の確かめができなかった（壊れた UTF-8・正規表現の失敗）。確かめられない文はそのまま返さない。
 * 例外の文には、文の中身を入れない（場所と誤りの名前だけ）
 */
final class UnreadableText extends \RuntimeException {}
