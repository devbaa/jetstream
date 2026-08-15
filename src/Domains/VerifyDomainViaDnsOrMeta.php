<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Domains;

use Illuminate\Support\Facades\Http;
use Laravel\Jetstream\Contracts\VerifiesDomains;
use Laravel\Jetstream\DomainClaim;
use Throwable;

class VerifyDomainViaDnsOrMeta implements VerifiesDomains
{
    /**
     * The number of validated addresses that are contacted per host.
     *
     * Together with the two candidate hosts this bounds a verification
     * attempt to a handful of outbound requests, no matter how many
     * addresses the domain's DNS answers with.
     */
    protected const MAX_ADDRESSES_PER_HOST = 2;

    /**
     * The number of seconds an individual home page request may take.
     */
    protected const REQUEST_TIMEOUT = 5;

    /**
     * Check whether the claim's verification token is published on the domain.
     */
    public function verify(DomainClaim $claim): ?string
    {
        if ($this->hasTxtRecord($claim)) {
            return 'dns';
        }

        if ($this->hasMetaTag($claim)) {
            return 'meta';
        }

        return null;
    }

    /**
     * Determine if the domain publishes the token as a DNS TXT record.
     */
    protected function hasTxtRecord(DomainClaim $claim): bool
    {
        $expected = $claim->recordValue();

        foreach ($this->resolveTxtRecords($claim->domain) as $record) {
            $values = [];

            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }

            if (isset($record['entries']) && is_array($record['entries'])) {
                $values = array_merge($values, $record['entries']);
            }

            foreach ($values as $value) {
                if (is_string($value) && trim($value) === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if the domain's home page publishes the token as a meta tag.
     *
     * The homepage is fetched without following redirects so that a token
     * served by a different host (via a cross-origin redirect) cannot verify
     * the claim, and only hosts that resolve exclusively to public IP
     * addresses are fetched to avoid server-side request forgery against
     * internal services. Each request is pinned to an address that was
     * already validated, so a second DNS lookup performed by the HTTP client
     * cannot swap in an internal address after the check (DNS rebinding).
     */
    protected function hasMetaTag(DomainClaim $claim): bool
    {
        // The connection is pinned through a cURL option. Without the cURL
        // extension the HTTP client falls back to a stream handler that would
        // resolve the host again and silently drop the pinning, so meta
        // verification is skipped rather than performed unprotected.
        if (! $this->canPinConnections()) {
            return false;
        }

        foreach ([$claim->domain, 'www.'.$claim->domain] as $host) {
            foreach ($this->resolvePublicAddresses($host) as $address) {
                if ($this->homePageContainsToken($host, $address, $claim)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fetch the host's home page from the given address and scan its head.
     */
    protected function homePageContainsToken(string $host, string $address, DomainClaim $claim): bool
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withoutRedirecting()
                ->withOptions($this->requestOptions($host, $address))
                ->get('https://'.$host);
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $this->headContainsToken($response->body(), $claim);
    }

    /**
     * Build the client options for a home page request pinned to an address.
     *
     * The URL keeps the original hostname so TLS verification, SNI and the
     * Host header all continue to target the domain being verified; only the
     * socket's destination is fixed to the validated address.
     *
     * @return array<string, mixed>
     */
    protected function requestOptions(string $host, string $address): array
    {
        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => [$host.':443:'.$this->resolveEntryAddress($address)],
            ],
        ];
    }

    /**
     * Determine if outbound requests can be pinned to a validated address.
     */
    protected function canPinConnections(): bool
    {
        return extension_loaded('curl');
    }

    /**
     * Format an address for a cURL resolve entry.
     *
     * IPv6 addresses must be bracketed so their colons are not mistaken for
     * the entry's field separators.
     */
    protected function resolveEntryAddress(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }

    /**
     * Resolve the host to the public addresses that may be contacted.
     *
     * The host is rejected as a whole — an empty list is returned — when any
     * answer is malformed, private or reserved. Mixed answers are a
     * signature of rebinding and SSRF filter bypasses, so the safe answers
     * are not simply picked out of them.
     *
     * @return list<string>
     */
    protected function resolvePublicAddresses(string $host): array
    {
        $records = $this->resolveDnsRecords($host);

        if ($records === []) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (! is_string($address) || ! $this->isPublicIp($address)) {
                return [];
            }

            $addresses[] = $address;
        }

        return array_slice(array_values(array_unique($addresses)), 0, self::MAX_ADDRESSES_PER_HOST);
    }

    /**
     * Look up the host's A and AAAA records.
     *
     * Isolated into its own method so resolution can be substituted in tests
     * without reaching for the network.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function resolveDnsRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        return is_array($records) ? $records : [];
    }

    /**
     * Look up the host's TXT records.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function resolveTxtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        return is_array($records) ? $records : [];
    }

    /**
     * Determine if the given IP address is public (not private or reserved).
     */
    protected function isPublicIp(string $ip): bool
    {
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if (! is_string($valid)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
            || $this->isPublicIpv6($ip);
    }

    /**
     * Determine if the given IPv6 address is routable on the public internet.
     *
     * The non-routable ranges are rejected explicitly rather than relying
     * solely on the filter extension's notion of private and reserved
     * ranges, which has changed between PHP releases.
     */
    protected function isPublicIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if (! is_string($packed) || strlen($packed) !== 16) {
            return false;
        }

        // The unspecified address, loopback, IPv4-compatible and IPv4-mapped
        // addresses all live below ::1:0:0:0 and would tunnel the request to
        // an embedded (potentially internal) IPv4 address...
        if (str_starts_with($packed, str_repeat("\x00", 10))) {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        // Unique local addresses, fc00::/7...
        if (($first & 0xFE) === 0xFC) {
            return false;
        }

        // Link-local unicast, fe80::/10...
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return false;
        }

        // Multicast, ff00::/8...
        return $first !== 0xFF;
    }

    /**
     * Determine if the given HTML document's head contains the claim's token.
     *
     * Only the document head is scanned so that a meta tag injected into
     * page content (user-generated HTML, comments, profile fields) cannot be
     * used to spoof ownership of the domain.
     */
    protected function headContainsToken(string $html, DomainClaim $claim): bool
    {
        $head = $this->extractHead($html);

        if (preg_match_all('/<meta\b[^>]*>/i', $head, $matches) === false) {
            return false;
        }

        $name = '/\bname\s*=\s*["\']'.preg_quote(DomainClaim::VERIFICATION_NAME, '/').'["\']/i';
        $content = '/\bcontent\s*=\s*["\']'.preg_quote($claim->token, '/').'["\']/i';

        foreach ($matches[0] as $tag) {
            if (preg_match($name, $tag) === 1 && preg_match($content, $tag) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the contents of the document head, stopping at the body.
     */
    protected function extractHead(string $html): string
    {
        if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $matches) === 1) {
            return $matches[1];
        }

        // No explicit head: scan everything before the body opening tag.
        $bodyPosition = stripos($html, '<body');

        return $bodyPosition === false ? $html : substr($html, 0, $bodyPosition);
    }
}
