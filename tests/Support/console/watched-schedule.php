<?php

// アプリの routes/console.php の代わり。Console Kernel が命令を読むときだけ読まれる
use Illuminate\Support\Facades\Schedule;

Schedule::command('fixture:task')->hourly()->watched('fixture-task', 60);
