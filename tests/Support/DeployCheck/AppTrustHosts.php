<?php

namespace Miraqceed\Foundation\Tests\Support\DeployCheck;

use Illuminate\Http\Middleware\TrustHosts;

/** 古い作りのアプリの TrustHosts の子（hosts() を書く） */
class AppTrustHosts extends TrustHosts
{
    /** @return array<int, string|null> */
    public function hosts(): array
    {
        return [$this->allSubdomainsOfApplicationUrl()];
    }
}
