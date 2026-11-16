<?php

namespace Miraqceed\Foundation\DeployCheck\Internal;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * 信頼するプロキシと見出しを、Laravel の TrustProxies と同じ読み方で読む（at() で入る値は protected static で、読み出しの口がない）。
 * 子のクラスは親と同じ静的な値を読む。要求は流さない。
 *
 * @internal
 */
final class TrustedProxySettings extends TrustProxies
{
    /** at() の値か、なければ config の trustedproxy.proxies（形はアプリしだい） */
    public function trusted(): mixed
    {
        // TrustProxies::setTrustedProxyIpAddresses() と同じ順（at() の値、なければ config）
        return $this->proxies() ?: config('trustedproxy.proxies');
    }

    public function trustedHeaders(): int
    {
        return $this->getTrustedHeaderNames();
    }
}
