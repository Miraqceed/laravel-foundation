<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Illuminate\Http\Middleware\TrustProxies;

/** 古い作りのアプリの TrustProxies の子（$proxies を書き替える） */
class AppTrustProxies extends TrustProxies
{
    protected $proxies = '*';
}
