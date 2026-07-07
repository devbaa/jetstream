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
     */
    protected function hasMetaTag(DomainClaim $claim): bool
    {
        foreach (['https://'.$claim->domain, 'https://www.'.$claim->domain] as $url) {
            try {
                $response = Http::timeout(5)->get($url);
            } catch (Throwable) {
                continue;
            }

            if ($response->successful() && $this->htmlContainsToken($response->body(), $claim)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given HTML contains the claim's verification meta tag.
     */
    protected function htmlContainsToken(string $html, DomainClaim $claim): bool
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $matches) === false) {
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
}
