<?php

namespace Miraqceed\Foundation\Privacy;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * database のセッションの表に、訪問者の IP を書かない（ip_address はいつも null）。
 *
 * Laravel の DatabaseSessionHandler は、ログインしていない訪問者も含めて、Web の要求のたびに request()->ip() を書く。
 * user_id・user_agent・payload・last_activity は Laravel のまま書く。
 * foundation.privacy.sessions_without_ip を真にすると、パッケージが database の driver をこれに差し替える（driver の名前は database のまま）。
 */
class DatabaseSessionHandlerWithoutIp extends DatabaseSessionHandler
{
    protected function ipAddress(): ?string
    {
        return null;
    }
}
