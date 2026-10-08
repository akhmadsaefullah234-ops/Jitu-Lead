<?php

namespace App\WhatsApp;

/**
 * Gateway addresses are typed in by tenant admins and called from our server,
 * so they must not be able to point at our own network.
 */
class GatewayUrlGuard
{
    /** @var (callable(string): array<int, string>)|null */
    private static $resolver = null;

    /**
     * Lets tests name hosts without real DNS.
     *
     * @param  (callable(string): array<int, string>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Returns null when the URL is acceptable, otherwise the reason it is not.
     */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Alamat gateway tidak valid.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Alamat gateway tidak boleh memuat nama pengguna atau password.';
        }

        $allowPrivate = (bool) config('whatsapp.allow_private_gateway_hosts');

        if (strtolower($parts['scheme']) !== 'https' && ! $allowPrivate) {
            return 'Alamat gateway harus memakai https.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Alamat gateway harus memakai http atau https.';
        }

        if ($allowPrivate) {
            return null;
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));

        if ($ips === []) {
            return 'Alamat gateway tidak bisa ditemukan.';
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Alamat gateway mengarah ke jaringan internal dan ditolak.';
            }
        }

        return null;
    }
}
