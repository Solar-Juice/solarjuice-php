<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Internal;

/**
 * UUID version 4 generation.
 *
 * @internal
 */
final class Uuid
{
    /**
     * Built from 16 cryptographically secure random bytes with the version and
     * variant bits set, rather than pulling in a UUID package: idempotency keys
     * only need to be unique, and a runtime dependency is not worth that.
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
