<?php

namespace Miraqceed\Foundation\Csv;

/** 読み込む CSV の文字コード */
enum SourceEncoding
{
    case Utf8;
    case Utf8Bom;
    case ShiftJis;
}
