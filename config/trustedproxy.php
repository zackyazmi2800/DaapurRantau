<?php

// Only explicit IPv4/IPv6 addresses or CIDR ranges of the reverse proxy in front of this
// server. Wildcards and catch-all ranges are dropped on purpose: trusting every client would
// let anyone forge X-Forwarded-For (rate-limit keys) and X-Forwarded-Proto (HTTPS detection).
$proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))), function (string $proxy) {
    [$ip, $mask] = array_pad(explode('/', $proxy, 2), 2, null);
    $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : null);

    return $version !== null && ($mask === null || (ctype_digit($mask) && (int) $mask >= ($version === 4 ? 8 : 32) && (int) $mask <= ($version === 4 ? 32 : 128)));
}));

return [
    // Always an array (possibly empty) so the framework never falls back to trusting "*".
    'proxies' => $proxies,
    // "x-forwarded": X-Forwarded-For/Host/Port/Proto. "forwarded": RFC 7239 Forwarded header.
    'headers' => strtolower((string) env('TRUSTED_PROXY_HEADERS', 'x-forwarded')) === 'forwarded' ? 'forwarded' : 'x-forwarded',
];
