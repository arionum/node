<?php

namespace Arionum;

/**
 * Class Blacklist
 */
final class Blacklist
{
    /**
     * The official list of blacklisted public keys
     */
    public const PUBLIC_KEYS = [];

    /**
     * Check if a public key is blacklisted
     *
     * @param string $publicKey
     * @return bool
     */
    public static function checkPublicKey(string $publicKey): bool
    {
        return key_exists($publicKey, static::PUBLIC_KEYS);
    }
}
