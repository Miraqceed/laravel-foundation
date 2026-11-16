<?php

namespace Miraqceed\Foundation\Mail;

/** Classifies selected reserved domains from RFC 2606 and RFC 6761 without DNS or I/O. */
final class ReservedEmailDomains
{
    private const TOP_LEVEL = ['invalid', 'test', 'example', 'localhost'];

    private const DOMAINS = ['example.com', 'example.net', 'example.org'];

    /**
     * Classify the domain after the last ASCII @ in a single bare email address.
     *
     * Domain normalization uses PHP trim, ASCII lowercase and removal of all
     * trailing ASCII dots. This is not email syntax or deliverability validation:
     * false only means the domain did not match the reserved names above.
     * Display names, URL/recipient-list parsing and IDN conversion are not supported.
     * Full-width separators are not converted.
     * The caller decides whether to exclude a reserved address from delivery.
     */
    public static function isReserved(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        $domain = rtrim(strtolower(trim(substr($address, $at + 1))), '.');
        if ($domain === '') {
            return false;
        }

        $dot = strrpos($domain, '.');
        $topLevel = $dot === false ? $domain : substr($domain, $dot + 1);
        if (in_array($topLevel, self::TOP_LEVEL, true)) {
            return true;
        }

        foreach (self::DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }
}
