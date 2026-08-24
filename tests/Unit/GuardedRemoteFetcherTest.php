<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Unit;

use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Support\Remote\AddressGuard;
use Kryption\MediaHub\Support\Remote\GuardedRemoteFetcher;
use Kryption\MediaHub\Support\Remote\RemoteAddress;
use PHPUnit\Framework\TestCase;

/**
 * FOLLOWING A URL WITHOUT BEING LED SOMEWHERE ELSE.
 *
 * ⚠️ THE TRANSPORT IS A FAKE, AND THAT IS WHAT MAKES THESE TESTS WORTH RUNNING. What is under
 * test is the sequence of decisions — check, connect to what was checked, check the next hop,
 * stop counting bytes at the cap — and none of it needs a server. Tests that reached the network
 * would prove the internet was up.
 */
class GuardedRemoteFetcherTest extends TestCase
{
    /** @var array<int, RemoteAddress> */
    private array $visited = [];

    /** @var array<string, array<int, string>> */
    private array $dns = [
        'example.com' => ['93.184.216.34'],
        'elsewhere.test' => ['93.184.216.35'],
        'evil.test' => ['169.254.169.254'],
    ];

    private function guard(): AddressGuard
    {
        return new AddressGuard(fn (string $host): array => $this->dns[$host] ?? []);
    }

    /**
     * @param  array<int, array{status: int, location?: string, body?: string}>  $answers
     */
    private function fetcher(array $answers, int $maxBytes = 1024, int $maxRedirects = 3): GuardedRemoteFetcher
    {
        $remaining = $answers;

        return new GuardedRemoteFetcher(
            $this->guard(),
            function (RemoteAddress $address, int $cap) use (&$remaining): array {
                $this->visited[] = $address;

                $answer = array_shift($remaining) ?? ['status' => 200, 'body' => 'bytes'];

                $stream = fopen('php://memory', 'r+b');
                fwrite($stream, $answer['body'] ?? '');
                rewind($stream);

                return [
                    'status' => $answer['status'],
                    'location' => $answer['location'] ?? null,
                    'stream' => $stream,
                ];
            },
            $maxBytes,
            $maxRedirects,
        );
    }

    protected function tearDown(): void
    {
        $this->visited = [];

        parent::tearDown();
    }

    public function test_it_brings_back_what_was_served(): void
    {
        $path = $this->fetcher([['status' => 200, 'body' => 'a picture']])->fetch('https://example.com/a.png');

        self::assertSame('a picture', file_get_contents($path));

        @unlink($path);
    }

    /**
     * ⚠️ THE TRANSPORT IS TOLD THE ADDRESS, NOT ONLY THE NAME. Resolving a second time is the
     * whole rebinding attack — the first answer passes the guard and the second is loopback.
     */
    public function test_it_hands_the_transport_the_address_that_was_checked(): void
    {
        $path = $this->fetcher([['status' => 200, 'body' => 'x']])->fetch('https://example.com/a.png');

        self::assertSame('93.184.216.34', $this->visited[0]?->ip);

        @unlink($path);
    }

    // ── Redirects ────────────────────────────────────────────────────────────

    public function test_it_follows_a_redirect(): void
    {
        $path = $this->fetcher([
            ['status' => 302, 'location' => 'https://elsewhere.test/b.png'],
            ['status' => 200, 'body' => 'the picture'],
        ])->fetch('https://example.com/a.png');

        self::assertSame('the picture', file_get_contents($path));
        self::assertSame('elsewhere.test', $this->visited[1]?->host);

        @unlink($path);
    }

    /**
     * ⚠️ THE HOP THAT MATTERS IS THE ONE PEOPLE FORGET. A public URL answering `302` towards the
     * cloud metadata endpoint is the shape of this attack, and a client that follows redirects
     * on its own walks straight into it.
     */
    public function test_it_refuses_a_redirect_towards_something_internal(): void
    {
        $this->refuses('remote_address_not_allowed', fn () => $this->fetcher([
            ['status' => 302, 'location' => 'http://evil.test/metadata'],
        ])->fetch('https://example.com/a.png'));
    }

    public function test_it_refuses_a_redirect_towards_another_scheme(): void
    {
        $this->refuses('remote_scheme_not_allowed', fn () => $this->fetcher([
            ['status' => 302, 'location' => 'file:///etc/passwd'],
        ])->fetch('https://example.com/a.png'));
    }

    /** ⚠️ A RELATIVE HOP IS RESOLVED AGAINST WHERE IT CAME FROM, then checked like any other. */
    public function test_it_resolves_a_relative_redirect_against_the_hop_it_came_from(): void
    {
        $path = $this->fetcher([
            ['status' => 302, 'location' => '/elsewhere/b.png'],
            ['status' => 200, 'body' => 'x'],
        ])->fetch('https://example.com/a.png');

        self::assertSame('https://example.com/elsewhere/b.png', $this->visited[1]?->url);

        @unlink($path);
    }

    public function test_it_stops_going_round_in_circles(): void
    {
        $this->refuses('remote_too_many_redirects', fn () => $this->fetcher([
            ['status' => 302, 'location' => 'https://example.com/1'],
            ['status' => 302, 'location' => 'https://example.com/2'],
            ['status' => 302, 'location' => 'https://example.com/3'],
            ['status' => 302, 'location' => 'https://example.com/4'],
            ['status' => 302, 'location' => 'https://example.com/5'],
        ])->fetch('https://example.com/a.png'));
    }

    public function test_it_refuses_a_redirect_that_says_nowhere(): void
    {
        $this->refuses('remote_unreachable', fn () => $this->fetcher([
            ['status' => 302],
        ])->fetch('https://example.com/a.png'));
    }

    // ── Size and failure ─────────────────────────────────────────────────────

    /**
     * ⚠️ COUNTED WHILE IT ARRIVES, NOT AFTERWARDS. A `Content-Length` is a claim by the other
     * side; checking the file once it is complete means the disk has already taken everything it
     * was sent.
     */
    public function test_it_stops_reading_at_the_cap(): void
    {
        $this->refuses('remote_too_large', fn () => $this->fetcher(
            [['status' => 200, 'body' => str_repeat('a', 5000)]],
            1024,
        )->fetch('https://example.com/a.png'));
    }

    public function test_it_leaves_nothing_behind_when_it_refuses_a_large_file(): void
    {
        $before = glob(sys_get_temp_dir().'/mediahub-remote*') ?: [];

        try {
            $this->fetcher([['status' => 200, 'body' => str_repeat('a', 5000)]], 1024)
                ->fetch('https://example.com/a.png');
        } catch (OperationRejected) {
            /* expected */
        }

        $after = glob(sys_get_temp_dir().'/mediahub-remote*') ?: [];

        self::assertSame(count($before), count($after));
    }

    public function test_it_refuses_a_status_that_is_not_a_success(): void
    {
        $this->refuses('remote_unreachable', fn () => $this->fetcher([
            ['status' => 404],
        ])->fetch('https://example.com/a.png'));
    }

    /** ⚠️ AN EMPTY ANSWER IS NOT A FILE — storing it would create a media nobody can open. */
    public function test_it_refuses_an_empty_answer(): void
    {
        $this->refuses('remote_empty', fn () => $this->fetcher([
            ['status' => 200, 'body' => ''],
        ])->fetch('https://example.com/a.png'));
    }

    /** ⚠️ AND THE ADDRESS IS CHECKED BEFORE ANYTHING IS SENT, not after the first answer. */
    public function test_it_refuses_an_internal_address_without_asking_the_transport(): void
    {
        $fetcher = new GuardedRemoteFetcher(
            $this->guard(),
            static function (): array {
                self::fail('nothing must be sent to a refused address');
            },
        );

        $this->refuses('remote_address_not_allowed', fn () => $fetcher->fetch('http://evil.test/a.png'));
    }

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
