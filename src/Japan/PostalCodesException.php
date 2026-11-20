<?php

namespace Miraqceed\Foundation\Japan;

use RuntimeException;

/**
 * 郵便番号の一覧を作れない（日本郵便のデータを取れない・ZIP を解けない・データの形が違う）。
 * 文には取った URL と理由だけを書き、データの中身を書かない
 */
class PostalCodesException extends RuntimeException
{
}
