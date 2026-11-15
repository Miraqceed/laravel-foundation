<?php

namespace Miraqceed\Foundation\TestSafety;

use RuntimeException;

/**
 * テストが、名前にテスト用の印のない DB へつなごうとした。DB に触れる前に投げる。
 */
class UnsafeTestDatabase extends RuntimeException {}
