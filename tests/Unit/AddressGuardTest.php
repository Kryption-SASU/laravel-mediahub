<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Unit;

use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Support\Remote\AddressGuard;
use PHPUnit\Framework\TestCase;

/**
 * THE GUARD, READ AS AN ATTACKER WOULD.
 *
 * ⚠️ EVERY CASE HERE IS A REAL WAY PAST A NAIVE CHECK, not a hypothetical. They are written as
 * attacks rather than as features because that is the only way to find out whether the guard
 * holds: a test that fetches `https://example.com` and passes tells you nothing at all.
 *
 * ⚠️ AND NOTHING HERE TOUCHES A NETWORK. Resolution is injected, so what is under test is the
 * decision and not the weather. A guard whose tests need DNS is a guard nobody runs.
 */
class AddressGuardTest extends TestCase
{
    /**
     * @param  array<int, string>  $answers
     * @param  array<int, string>  $hosts
     */
    private function guard(array $answers = ['93.184.216.34'], array $hosts = []): AddressGuard
    {
        return new AddressGuard(
            static fn (string $host): array => $answers,
            ['http', 'https'],
            [80, 443],
            $hosts,
        );
    }

    public function test_it_accepts_an_ordinary_public_address(): void
    {
        $checked = $this->guard()->inspect('https://example.com/photo.png');

        self::assertSame('example.com', $checked->host);
        self::assertSame(443, $checked->port);
        self::assertSame('93.184.216.34', $checked->ip);
    }

    /**
     * ⚠️ THE ADDRESS TRAVELS WITH THE URL, and that is the whole defence against rebinding.
     * Resolving once and connecting to the answer is what stops a second lookup from going
     * somewhere else.
     */
    public function test_it_hands_back_the_address_it_checked(): void
    {
        $checked = $this->guard(['93.184.216.34', '93.184.216.35'])->inspect('https://example.com/a.png');

        self::assertSame('93.184.216.34', $checked->ip);
    }

    /**
     * ⚠️ ONE PRIVATE ANSWER POISONS THE WHOLE NAME. A host answering with a public address and a
     * private one is a rebinding attack served in a single response: taking the first would
     * work, and a retry would take the other.
     */
    public function test_it_refuses_a_name_that_answers_with_anything_internal(): void
    {
        $this->refuses('remote_address_not_allowed', fn () => $this->guard(['93.184.216.34', '127.0.0.1'])->inspect('https://example.com/a.png'));
    }

    // ── Schemes ──────────────────────────────────────────────────────────────

    /**
     * @param  string  $url
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenSchemes')]
    public function test_it_refuses_a_scheme_that_is_not_http(string $url): void
    {
        $this->refuses('remote_scheme_not_allowed', fn () => $this->guard()->inspect($url));
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenSchemes(): array
    {
        return [
            /* Reads the disk. */
            'file' => ['file:///etc/passwd'],
            /* Speaks enough of other protocols to give a Redis on localhost an order. */
            'gopher' => ['gopher://example.com:6379/_SET%20a%20b'],
            'ftp' => ['ftp://example.com/a.png'],
            'dict' => ['dict://example.com:11211/stat'],
        ];
    }

    // ── Addresses ────────────────────────────────────────────────────────────

    /**
     * @param  string  $host
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('internalAddresses')]
    public function test_it_refuses_an_internal_address(string $host): void
    {
        $this->refuses('remote_address_not_allowed', fn () => $this->guard([$host])->inspect('https://example.com/a.png'));
    }

    /** @return array<string, array{0: string}> */
    public static function internalAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback, elsewhere in the range' => ['127.13.37.1'],
            'this host' => ['0.0.0.0'],
            'private, class A' => ['10.1.2.3'],
            'private, class B' => ['172.16.0.1'],
            'private, class B, top of range' => ['172.31.255.255'],
            'private, class C' => ['192.168.1.1'],
            /* Every major cloud hands out credentials here to anything that asks. */
            'cloud metadata' => ['169.254.169.254'],
            'link local' => ['169.254.1.1'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'benchmarking' => ['198.18.0.1'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unspecified' => ['::'],
            'IPv6 unique local' => ['fd00::1'],
            'IPv6 link local' => ['fe80::1'],
            /* ⚠️ THE OLDEST WAY PAST A GUARD THAT ONLY WALKS THE IPv6 TABLE. */
            'IPv4 loopback wearing IPv6' => ['::ffff:127.0.0.1'],
            'IPv4 private wearing IPv6' => ['::ffff:10.0.0.1'],
            '6to4, which embeds IPv4' => ['2002:7f00:1::1'],
            'NAT64, which embeds IPv4' => ['64:ff9b::7f00:1'],
        ];
    }

    /** ⚠️ A LITERAL ADDRESS IS NOT A NAME, and must be refused as the address it is. */
    public function test_it_refuses_a_literal_internal_address_without_asking_a_resolver(): void
    {
        $guard = new AddressGuard(
            static function (string $host): array {
                self::fail('a literal address must not be sent to a resolver');
            },
        );

        $this->refuses('remote_address_not_allowed', fn () => $guard->inspect('http://127.0.0.1/a.png'));
    }

    public function test_it_refuses_a_bracketed_internal_address(): void
    {
        $guard = new AddressGuard(static fn (string $host): array => []);

        $this->refuses('remote_address_not_allowed', fn () => $guard->inspect('http://[::1]/a.png'));
    }

    /**
     * ⚠️ DECIMAL AND OCTAL SPELLINGS OF AN ADDRESS. `http://2130706433/` is `127.0.0.1` to a
     * resolver, and to nobody reading the URL. Whatever this ends up being treated as, it must
     * not be reachable.
     */
    public function test_it_refuses_an_address_spelled_as_a_number(): void
    {
        $guard = new AddressGuard(static fn (string $host): array => ['127.0.0.1']);

        $this->refuses('remote_address_not_allowed', fn () => $guard->inspect('http://2130706433/a.png'));
    }

    // ── Everything else ──────────────────────────────────────────────────────

    public function test_it_refuses_a_port_that_is_not_on_the_list(): void
    {
        $this->refuses('remote_port_not_allowed', fn () => $this->guard()->inspect('http://example.com:6379/a.png'));
    }

    /**
     * ⚠️ CREDENTIALS ARE REFUSED RATHER THAN STRIPPED. They are usually there to reach something
     * the server can see and the person cannot.
     */
    public function test_it_refuses_credentials_in_the_url(): void
    {
        $this->refuses('remote_credentials_not_allowed', fn () => $this->guard()->inspect('https://someone:secret@example.com/a.png'));
    }

    public function test_it_refuses_something_that_is_not_a_url(): void
    {
        $this->refuses('remote_url_invalid', fn () => $this->guard()->inspect('not a url at all'));
    }

    public function test_it_refuses_a_name_that_answers_with_nothing(): void
    {
        $this->refuses('remote_unresolvable', fn () => $this->guard([])->inspect('https://example.com/a.png'));
    }

    // ── The allow-list ───────────────────────────────────────────────────────

    public function test_an_allow_list_accepts_what_is_on_it(): void
    {
        $checked = $this->guard(['93.184.216.34'], ['example.com'])->inspect('https://example.com/a.png');

        self::assertSame('example.com', $checked->host);
    }

    public function test_an_allow_list_refuses_everything_else(): void
    {
        $this->refuses('remote_host_not_allowed', fn () => $this->guard(['93.184.216.34'], ['example.com'])->inspect('https://elsewhere.test/a.png'));
    }

    /**
     * ⚠️ EXACT, NOT A SUFFIX. `example.com.attacker.test` ends with nothing that matters, but a
     * rule written as "ends with example.com" would welcome it — and the name is trivially
     * registered by whoever wants in.
     */
    public function test_an_allow_list_is_not_a_suffix_rule(): void
    {
        $this->refuses('remote_host_not_allowed', fn () => $this->guard(['93.184.216.34'], ['example.com'])->inspect('https://example.com.attacker.test/a.png'));
    }

    /**
     * ⚠️ THE KEY IS COMPARED, NOT THE SENTENCE. `reason` is the contract a program branches on
     * and never changes; the message is a courtesy that changes with the wording and the
     * language. A test on the sentence would go red for an improvement to it, and green for a
     * refusal made for entirely the wrong reason.
     */
    private function refuses(string $reason, callable $act): void
    {
        try {
            $act();
        } catch (OperationRejected $rejected) {
            self::assertSame($reason, $rejected->reason);

            return;
        }

        self::fail('nothing was refused, and "'.$reason.'" was expected');
    }
}
