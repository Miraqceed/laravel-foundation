<?php

namespace Miraqceed\Foundation\DeployCheck;

/**
 * 調べ1つの結果。detail には設定の値（秘密）を入れない。何が違うかを、値なしで書く
 */
final readonly class Result
{
    private function __construct(
        public Status $status,
        public ?string $detail = null,
    ) {}

    public static function ok(?string $detail = null): self
    {
        return new self(Status::Ok, $detail);
    }

    public static function fail(?string $detail = null): self
    {
        return new self(Status::Fail, $detail);
    }

    public static function later(?string $detail = null): self
    {
        return new self(Status::Later, $detail);
    }

    public static function skip(?string $detail = null): self
    {
        return new self(Status::Skip, $detail);
    }

    public static function info(string $detail): self
    {
        return new self(Status::Info, $detail);
    }
}
