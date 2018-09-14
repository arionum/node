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
    public const PUBLIC_KEYS = [
        // phpcs:disable Generic.Files.LineLength
        'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCvVQcHHCNLfiP9LmzWhhpCHx39Bhc67P5HMQM9cctEFvcsUdgrkGqy18taz9ZMrAGtq7NhBYpQ4ZTHkKYiZDaSUqQ' => 'Faucet Abuser',
        'PZ8Tyr4Nx8MHsRAGMpZmZ6TWY63dXWSCxYDeQHk7Ke66UB2Un3UMmMoJ7RF5vDZXihdEXi8gk8ZBRAi35aFrER2ZLX1mgND7sLFXKETGTjRYjoHcuRNiJN1g' => 'Octaex Exchange',
        // phpcs:enable
    ];

    /**
     * The official list of blacklisted addresses
     */
    public const ADDRESSES = [
        // phpcs:disable Generic.Files.LineLength
        'xuzyMbEGA1tmx1o7mcxSXf2nXuuV1GtKbA4sAqjcNq2gh3shuhwBT5nJHez9AynCaxpJwL6dpkavmZBA3JkrMkg' => 'Octaex Exchange',
        // phpcs:enable
    ];

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

    /**
     * Check if an address is blacklisted
     *
     * @param string $address
     * @return bool
     */
    public static function checkAddress(string $address): bool
    {
        return key_exists($address, static::ADDRESSES);
    }
}
