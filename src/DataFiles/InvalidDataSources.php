<?php

namespace Miraqceed\Foundation\DataFiles;

use LogicException;

/**
 * foundation.data_files.sources の書き違い（Sources が投げる）。文はこの部品が決めた形だけで、設定の値もアプリの例外の文も入れない。
 * 命令はこの文だけをそのまま出す。アプリの種類が投げた例外は、種類の名前だけにして包む
 */
final class InvalidDataSources extends LogicException {}
