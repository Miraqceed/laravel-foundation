<?php

namespace Miraqceed\Foundation\DeployCheck;

/**
 * foundation:deploy-check の調べ1つ。アプリは config の foundation.deploy_check.checks にクラス名を並べて足す（容器から作る）。
 *
 * - 読むだけにする。DB・キャッシュ・ファイルに書かない。ログを書かない
 * - label()・Result の detail に、設定の値（鍵・パスワード・接続先）を入れない
 * - いつ必須になるかは調べが決める：公開の前は later、ただし値が入っていて形が違えば公開の前でも fail
 */
interface Check
{
    /** 変えない id（英小文字・数字・_。英小文字で始める）。CI の台本と foundation.deploy_check.skip が使う */
    public function id(): string;

    /** 満たすべきこと。値を入れない */
    public function label(): string;

    public function run(): Result;
}
