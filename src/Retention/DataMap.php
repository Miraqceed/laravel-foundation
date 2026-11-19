<?php

namespace Miraqceed\Foundation\Retention;

/**
 * 消す決まりの台帳。アプリが1つのクラスに、すべての表の消し方を書き、設定 foundation.retention.map にクラスの名前を書く。
 * パッケージ自身の表の分は、パッケージが足す（アプリが同じ表を書けば、アプリが勝つ）
 */
interface DataMap
{
    public function define(MapBuilder $map): void;
}
