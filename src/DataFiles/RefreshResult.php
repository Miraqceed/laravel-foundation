<?php

namespace Miraqceed\Foundation\DataFiles;

final class RefreshResult
{
    public function __construct(
        public readonly RefreshStatus $status,
        public readonly string $message,
        public readonly string|int|null $version = null,
    ) {}
}
