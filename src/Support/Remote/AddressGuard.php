<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support\Remote;

use Kryption\MediaHub\Exceptions\OperationRejected;

/**
 * WHAT THE SERVER IS ALLOWED TO GO AND FETCH.
 *
 * ⚠️ FETCHING A URL SOMEBODY ELSE CHOSE IS A REQUEST-FORGERY PRIMITIVE. The server sits inside
 * the network: it can reach the database, the queue, the admin panel bound to localhost and —
 * on every major cloud — a metadata endpoint at `169.254.169.254` that hands out credentials to
 * anything that asks. A library that follows a URL without checking it turns "attach a picture
 * from the web" into "read me any internal page you like, and tell me what it said".
 *
 * ⚠️ SO THIS CLASS DOES NO I/O AND CAN BE READ IN ONE SITTING. Resolution is injected, which is
 * also what lets every one of these rules be tested without a network, a name server, or luck.
 *
 * ⚠️ AND IT REFUSES BY DEFAULT. Anything it does not understand — a scheme it was not given, an
 * address it cannot parse — is refused rather than allowed through. A guard whose unknown cases
 * fall open is a guard that protects only what somebody already thought of.
 */
final class AddressGuard
{
    /**
     * Ranges no request from this server has any business reaching.
     *
     * ⚠️ THIS IS MORE THAN "PRIVATE". Loopback and RFC 1918 are the obvious ones; the rest are
     * the ones people forget. `169.254.0.0/16` carries cloud metadata. `100.64.0.0/10` is
     * carrier-grade NAT, routable inside a provider's network. `0.0.0.0/8` reaches the local
     * host on Linux. Multicast and the reserved top of the space answer in ways nobody audits.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const REFUSED_V4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /**
     * @var array<int, array{0: string, 1: int}>
     */
    private const REFUSED_V6 = [
        ['::', 128],
        ['::1', 128],
        ['fc00::', 7],
        ['fe80::', 10],
        ['ff00::', 8],
        /* 6to4 and NAT64 both embed an IPv4 address, which would otherwise slip past unchecked. */
        ['2002::', 16],
        ['64:ff9b::', 96],
    ];

    /**
     * @param  callable(string): array<int, string>  $resolve  Hostname to addresses.
     * @param  array<int, string>  $schemes
     * @param  array<int, int>  $ports
     * @param  array<int, string>  $hosts  Empty means any host that is not refused below.
     */
    public function __construct(
        private $resolve,
        private readonly array $schemes = ['http', 'https'],
        private readonly array $ports = [80, 443],
        private readonly array $hosts = [],
    ) {
    }

    /**
     * @throws OperationRejected
     */
    public function inspect(string $url): RemoteAddress
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw OperationRejected::because('remote_url_invalid', 'That address cannot be read.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        /*
         * ⚠️ NO SCHEME AT ALL IS NOT THE SAME COMPLAINT AS A SCHEME WE REFUSE. Somebody pasting
         * a stray line of text should read "that address cannot be read"; somebody trying
         * `file://` should read that the kind of address is refused. Collapsing the two makes
         * one of the two messages actively misleading.
         */
        if ($scheme === '') {
            throw OperationRejected::because('remote_url_invalid', 'That address cannot be read.');
        }

        /*
         * ⚠️ `file://`, `gopher://` AND FRIENDS ARE NOT OVERSIGHTS TO PATCH LATER. `file:///etc/passwd`
         * reads the disk; `gopher://` can be made to speak enough of other protocols to send a
         * command to a Redis on localhost. Only what is on the list is allowed.
         */
        if (! in_array($scheme, $this->schemes, true)) {
            throw OperationRejected::because('remote_scheme_not_allowed', 'That kind of address is not accepted.');
        }

        /*
         * ⚠️ CREDENTIALS IN A URL ARE REFUSED RATHER THAN STRIPPED. They are usually there to
         * reach something the server can see and the person cannot, and silently dropping them
         * would turn a refusal into a confusing failure somewhere else.
         */
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw OperationRejected::because('remote_credentials_not_allowed', 'That address carries credentials.');
        }

        /*
         * ⚠️ THE SCHEME IS JUDGED BEFORE THE HOST, and the order changes what a caller is told.
         * `file:///etc/passwd` has no host at all: checking the host first refuses it as an
         * unreadable address, which is true and useless — a host branching on the reason to say
         * "only web links are accepted" would never see it.
         */
        if (! isset($parts['host']) || $parts['host'] === '') {
            throw OperationRejected::because('remote_url_invalid', 'That address cannot be read.');
        }

        $host = strtolower((string) $parts['host']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, $this->ports, true)) {
            throw OperationRejected::because('remote_port_not_allowed', 'That port is not accepted.');
        }

        /*
         * ⚠️ AN ALLOW-LIST, WHERE THERE IS ONE, IS THE WHOLE ANSWER. A host that names the places
         * it trusts does not need the address rules below to be exhaustive — and exact matching
         * is deliberate: `example.com.attacker.test` matches a suffix rule and nothing else.
         */
        if ($this->hosts !== [] && ! in_array($host, $this->hosts, true)) {
            throw OperationRejected::because('remote_host_not_allowed', 'That address is not on the list.');
        }

        return new RemoteAddress($url, $scheme, $host, $port, $this->addressFor($host));
    }

    /**
     * ⚠️ EVERY ANSWER MUST PASS, NOT JUST THE ONE WE WOULD USE. A name that resolves to a public
     * address and a private one is a rebinding attack served in a single response: taking the
     * first would work, and the client's own retry would take the other.
     *
     * @throws OperationRejected
     */
    private function addressFor(string $host): string
    {
        $literal = $this->normalise($host);

        $addresses = $literal !== null ? [$literal] : ($this->resolve)($host);

        if ($addresses === []) {
            throw OperationRejected::because('remote_unresolvable', 'That address cannot be reached.');
        }

        foreach ($addresses as $address) {
            $this->refuseInternal($address);
        }

        return $addresses[0];
    }

    /**
     * ⚠️ A HOST CAN BE A LITERAL ADDRESS, brackets and all — `http://[::1]/`. Treating it as a
     * name would send it to the resolver, which would answer nothing, and the refusal would
     * come out as "unreachable" for something that must be refused as internal.
     */
    private function normalise(string $host): ?string
    {
        $bare = trim($host, '[]');

        return filter_var($bare, FILTER_VALIDATE_IP) === false ? null : $bare;
    }

    /**
     * @throws OperationRejected
     */
    private function refuseInternal(string $address): void
    {
        $packed = @inet_pton($address);

        /* ⚠️ UNPARSEABLE IS REFUSED, NOT IGNORED. */
        if ($packed === false) {
            throw OperationRejected::because('remote_address_not_allowed', 'That address is not accepted.');
        }

        /*
         * ⚠️ AN IPv4 ADDRESS WEARING AN IPv6 COSTUME IS STILL THAT ADDRESS. `::ffff:127.0.0.1`
         * is loopback, and a check that only walks the IPv6 table lets it through — which is one
         * of the oldest ways past exactly this kind of guard.
         */
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
            $address = (string) inet_ntop($packed);
        }

        $ranges = strlen($packed) === 4 ? self::REFUSED_V4 : self::REFUSED_V6;

        foreach ($ranges as [$network, $bits]) {
            if ($this->within($packed, (string) inet_pton($network), $bits)) {
                throw OperationRejected::because('remote_address_not_allowed', 'That address is not accepted.');
            }
        }
    }

    private function within(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($whole > 0 && substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = 0xff << (8 - $rest) & 0xff;

        return (ord($address[$whole]) & $mask) === (ord($network[$whole]) & $mask);
    }
}
