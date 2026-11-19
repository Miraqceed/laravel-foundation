<?php

namespace Miraqceed\Foundation\Turnstile;

use RuntimeException;

/**
 * Turnstile の確かめが、こちらの設定の誤り（秘密の鍵がない・違う）か Cloudflare の側の理由で、だれも通せない。
 * 記録（FailureReporter）と知らせ（AlertSender）のためだけに作る。投げない。文に秘密の鍵・トークン・IP を入れない
 */
final class TurnstileMisconfigured extends RuntimeException {}
