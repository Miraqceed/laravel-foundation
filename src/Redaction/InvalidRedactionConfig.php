<?php

namespace Miraqceed\Foundation\Redaction;

/**
 * 伏せる部品の設定の書き違い（知らない種類・作れない正規表現・印に当たる形など）。黙って既定に寄せずに止める
 */
final class InvalidRedactionConfig extends \InvalidArgumentException {}
