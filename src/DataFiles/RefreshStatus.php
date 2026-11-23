<?php

namespace Miraqceed\Foundation\DataFiles;

/** 1つの種類の更新の結果（失敗は DataFileException で投げる） */
enum RefreshStatus: string
{
    /** 新しい版を入れた */
    case Updated = 'updated';

    /** 取ったものが入っている版より新しくない（同じ・古い）。入れ替えない */
    case NotNewer = 'not-newer';

    /** 試す取り方がすべて「まだ出ていない」（404）。入っているものはそのまま */
    case NotYet = 'not-yet';

    /** ほかの実行が動いている（ロックが取れない）。何もしない */
    case Busy = 'busy';
}
