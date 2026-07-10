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
        $records = @dns_get_record($claim->domain, DNS_TXT);

        if (! is_array($records)) {
            return false;
        }

        $expected = $claim->recordValue();

        foreach ($records as $record) {
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
     * the claim, and only hosts that resolve to public IP addresses are
     * fetched to avoid server-side request forgery against internal services.
     */
    protected function hasMetaTag(DomainClaim $claim): bool
    {
        foreach ([$claim->domain, 'www.'.$claim->domain] as $host) {
            if (! $this->resolvesToPublicAddress($host)) {
                continue;
            }

            try {
                $response = Http::timeout(5)
                    ->withoutRedirecting()
                    ->withOptions(['allow_redirects' => false])
                    ->get('https://'.$host);
            } catch (Throwable) {
                continue;
            }

            if ($response->successful() && $this->headContainsToken($response->body(), $claim)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the host resolves only to public, routable IP addresses.
     */
    protected function resolvesToPublicAddress(string $host): bool
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! is_array($records) || $records === []) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (! is_string($ip) || ! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the given IP address is public (not private or reserved).
     */
    protected function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
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
