<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\DomainClaim;
use Illuminate\Support\Facades\Http;
use Laravel\Jetstream\Domains\VerifyDomainViaDnsOrMeta;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\RecordingDomainVerifier;
use Laravel\Jetstream\Tests\Fixtures\User;

class VerifyDomainViaDnsOrMetaTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::domainAdmin();

        $app->config->set('jetstream.features', $features);

        Jetstream::useUserModel(User::class);
    }

    protected function createClaim(string $domain = 'acme.com'): DomainClaim
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@'.$domain,
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);

        return DomainClaim::forceCreate([
            'user_id' => $user->id,
            'domain' => $domain,
            'token' => DomainClaim::generateToken(),
        ]);
    }

    /**
     * Build a verifier with canned DNS answers and recorded request options.
     *
     * @param  list<array<array-key, mixed>>  $addressRecords
     * @param  list<array<array-key, mixed>>  $txtRecords
     */
    protected function verifier(array $addressRecords = [], array $txtRecords = []): RecordingDomainVerifier
    {
        return new RecordingDomainVerifier($addressRecords, $txtRecords);
    }

    /**
     * Build the DNS answer shape returned by dns_get_record() for an address.
     *
     * @return array<array-key, mixed>
     */
    protected function address(string $ip): array
    {
        return str_contains($ip, ':') ? ['type' => 'AAAA', 'ipv6' => $ip] : ['type' => 'A', 'ip' => $ip];
    }

    protected function pageWithToken(DomainClaim $claim): string
    {
        return '<html><head><title>Acme</title>'.$claim->metaTag().'</head><body>Hello</body></html>';
    }

    public function test_a_matching_txt_record_verifies_the_domain()
    {
        $claim = $this->createClaim();

        Http::fake();

        $verifier = $this->verifier([], [['type' => 'TXT', 'txt' => $claim->recordValue()]]);

        $this->assertSame('dns', $verifier->verify($claim));

        Http::assertNothingSent();
    }

    public function test_an_unrelated_txt_record_does_not_verify_the_domain()
    {
        $claim = $this->createClaim();

        Http::fake();

        $verifier = $this->verifier([], [['type' => 'TXT', 'txt' => 'jetstream-domain-verification=not-the-token']]);

        $this->assertNull($verifier->verify($claim));
    }

    public function test_a_token_in_the_document_head_verifies_the_domain_over_a_public_address()
    {
        $claim = $this->createClaim();

        Http::fake(['https://acme.com' => Http::response($this->pageWithToken($claim))]);

        $verifier = $this->verifier([$this->address('93.184.216.34')]);

        $this->assertSame('meta', $verifier->verify($claim));
    }

    public function test_a_token_in_the_document_body_does_not_verify_the_domain()
    {
        $claim = $this->createClaim();

        Http::fake([
            '*' => Http::response('<html><head><title>Acme</title></head><body>'.$claim->metaTag().'</body></html>'),
        ]);

        $verifier = $this->verifier([$this->address('93.184.216.34')]);

        $this->assertNull($verifier->verify($claim));
    }

    public function test_a_different_token_in_the_document_head_does_not_verify_the_domain()
    {
        $claim = $this->createClaim();

        Http::fake([
            '*' => Http::response('<html><head><meta name="jetstream-domain-verification" content="wrong"></head></html>'),
        ]);

        $verifier = $this->verifier([$this->address('93.184.216.34')]);

        $this->assertNull($verifier->verify($claim));
    }

    public function test_a_redirect_to_a_page_carrying_the_token_does_not_verify_the_domain()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response($this->pageWithToken($claim), 302, ['Location' => 'https://elsewhere.test'])]);

        $verifier = $this->verifier([$this->address('93.184.216.34')]);

        $this->assertNull($verifier->verify($claim));

        foreach ($verifier->options as $options) {
            $this->assertFalse($options['allow_redirects']);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicAddressProvider(): array
    {
        return [
            'private class A' => ['10.0.0.1'],
            'private class B' => ['172.16.9.1'],
            'private class C' => ['192.168.1.1'],
            'loopback' => ['127.0.0.1'],
            'link local' => ['169.254.169.254'],
            'reserved this network' => ['0.0.0.0'],
            'reserved future use' => ['240.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 ipv4 mapped' => ['::ffff:169.254.169.254'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 unique local' => ['fd00::1'],
            'ipv6 unique local low' => ['fc00::1'],
            'ipv6 multicast' => ['ff02::1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonPublicAddressProvider')]
    public function test_hosts_resolving_to_a_non_public_address_are_never_contacted(string $ip)
    {
        $claim = $this->createClaim();

        Http::fake();

        $verifier = $this->verifier([$this->address($ip)]);

        $this->assertNull($verifier->verify($claim));

        Http::assertNothingSent();
        $this->assertSame([], $verifier->options);
    }

    public function test_a_host_without_dns_records_is_never_contacted()
    {
        $claim = $this->createClaim();

        Http::fake();

        $verifier = $this->verifier([]);

        $this->assertNull($verifier->verify($claim));

        Http::assertNothingSent();
    }

    public function test_mixed_public_and_private_dns_answers_reject_the_host_entirely()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response($this->pageWithToken($claim))]);

        $verifier = $this->verifier([
            $this->address('93.184.216.34'),
            $this->address('127.0.0.1'),
        ]);

        $this->assertNull($verifier->verify($claim));

        Http::assertNothingSent();
    }

    public function test_a_malformed_dns_answer_rejects_the_host_entirely()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response($this->pageWithToken($claim))]);

        $verifier = $this->verifier([
            $this->address('93.184.216.34'),
            ['type' => 'A', 'ip' => 'not-an-address'],
        ]);

        $this->assertNull($verifier->verify($claim));

        Http::assertNothingSent();
    }

    public function test_the_request_is_pinned_to_a_validated_address_without_weakening_tls()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response($this->pageWithToken($claim))]);

        $verifier = $this->verifier([$this->address('93.184.216.34')]);

        $this->assertSame('meta', $verifier->verify($claim));

        $this->assertNotSame([], $verifier->options);

        foreach ($verifier->options as $options) {
            $this->assertSame(
                ['acme.com:443:93.184.216.34'],
                $options['curl'][CURLOPT_RESOLVE]
            );

            $this->assertFalse($options['allow_redirects']);
            $this->assertArrayNotHasKey('verify', $options);
        }

        // The URL keeps the hostname so SNI, certificate validation and the
        // Host header all still target the domain being verified...
        Http::assertSent(fn ($request) => $request->url() === 'https://acme.com');
    }

    public function test_ipv6_addresses_are_bracketed_in_the_pinned_resolve_entry()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response($this->pageWithToken($claim))]);

        $verifier = $this->verifier([$this->address('2606:2800:220:1:248:1893:25c8:1946')]);

        $this->assertSame('meta', $verifier->verify($claim));

        $this->assertSame(
            ['acme.com:443:[2606:2800:220:1:248:1893:25c8:1946]'],
            $verifier->options[0]['curl'][CURLOPT_RESOLVE]
        );
    }

    public function test_the_number_of_outbound_requests_is_bounded_per_host()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response('<html><head></head><body></body></html>')]);

        $verifier = $this->verifier([
            $this->address('93.184.216.34'),
            $this->address('93.184.216.35'),
            $this->address('93.184.216.36'),
            $this->address('93.184.216.37'),
        ]);

        $this->assertNull($verifier->verify($claim));

        // Two candidate hosts (the bare domain and "www."), each capped at
        // two validated addresses...
        $this->assertCount(4, $verifier->options);
    }

    public function test_the_www_host_is_tried_when_the_bare_domain_does_not_publish_the_token()
    {
        $claim = $this->createClaim();

        Http::fake([
            'https://acme.com' => Http::response('<html><head></head><body></body></html>'),
            'https://www.acme.com' => Http::response($this->pageWithToken($claim)),
        ]);

        $verifier = $this->verifier([$this->address('93.184.216.34')]);

        $this->assertSame('meta', $verifier->verify($claim));

        $this->assertSame(
            ['www.acme.com:443:93.184.216.34'],
            $verifier->options[1]['curl'][CURLOPT_RESOLVE]
        );
    }

    public function test_meta_verification_is_skipped_when_connections_cannot_be_pinned()
    {
        $claim = $this->createClaim();

        Http::fake(['*' => Http::response($this->pageWithToken($claim))]);

        $verifier = new class([['type' => 'A', 'ip' => '93.184.216.34']], []) extends RecordingDomainVerifier
        {
            /** {@inheritdoc} */
            #[\Override]
            protected function canPinConnections(): bool
            {
                return false;
            }
        };

        $this->assertNull($verifier->verify($claim));

        Http::assertNothingSent();
    }

    public function test_the_shipped_verifier_is_the_registered_domain_verifier()
    {
        $this->assertInstanceOf(
            VerifyDomainViaDnsOrMeta::class,
            app(\Laravel\Jetstream\Contracts\VerifiesDomains::class)
        );
    }
}
