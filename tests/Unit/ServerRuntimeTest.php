<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Unit;

use Kryption\MediaHub\Support\ServerRuntime;
use PHPUnit\Framework\TestCase;

/**
 * WHICH PHP IS ANSWERING — the fact every piece of timeout advice hangs from.
 *
 * ⚠️ THE INTERFACE NAMES ARE PHP'S AND THEY ARE NOT GUESSABLE. The Apache module calls itself
 * `apache2handler`, and PHP-FPM calls itself `fpm-fcgi` rather than anything containing "fpm"
 * alone. A classification written from intuition puts `cgi-fcgi` — plain FastCGI, which has no
 * pool manager — under PHP-FPM, and then sends that host to a `php-fpm.conf` it does not have.
 */
class ServerRuntimeTest extends TestCase
{
    public function test_it_knows_the_interfaces_php_actually_reports(): void
    {
        $expected = [
            'fpm-fcgi' => ServerRuntime::POOLED,
            'apache2handler' => ServerRuntime::EMBEDDED,
            'cgi-fcgi' => ServerRuntime::GATEWAY,
            'cli' => ServerRuntime::CONSOLE,
            'cli-server' => ServerRuntime::CONSOLE,
        ];

        foreach ($expected as $sapi => $family) {
            $this->assertSame($family, (new ServerRuntime($sapi))->family(), $sapi.' was filed wrongly.');
        }
    }

    /**
     * ⚠️ THE TWO NAMES SHARE "FCGI" AND NOTHING ELSE. Matching on substrings passes every test
     * written from the happy path and fails only on somebody's server.
     */
    public function test_plain_fastcgi_is_not_mistaken_for_a_pool(): void
    {
        $this->assertNotSame(
            (new ServerRuntime('fpm-fcgi'))->family(),
            (new ServerRuntime('cgi-fcgi'))->family(),
        );
    }

    /**
     * ⚠️ WHAT IS NOT RECOGNISED IS SAID TO BE UNRECOGNISED rather than filed under the nearest
     * guess. LiteSpeed, FrankenPHP and RoadRunner each bound a request their own way; an honest
     * "this package cannot name yours" leaves somebody able to go and look, and a wrong file
     * name does not.
     */
    public function test_an_interface_it_does_not_know_is_left_unrecognised(): void
    {
        foreach (['litespeed', 'frankenphp', 'roadrunner', ''] as $sapi) {
            $this->assertSame(ServerRuntime::UNRECOGNISED, (new ServerRuntime($sapi))->family());
        }
    }

    /**
     * ⚠️ EVERY OTHER FAMILY SERVES THE WEB, INCLUDING ONES IT CANNOT NAME. A report that fell
     * back to "this is the console" for an unrecognised interface would tell somebody their
     * server's figures are irrelevant, which is both false and the sort of sentence that gets a
     * report closed for good.
     */
    public function test_only_the_console_is_not_serving_the_web(): void
    {
        $this->assertFalse((new ServerRuntime('cli'))->servesTheWeb());
        $this->assertFalse((new ServerRuntime('phpdbg'))->servesTheWeb());

        $this->assertTrue((new ServerRuntime('fpm-fcgi'))->servesTheWeb());
        $this->assertTrue((new ServerRuntime('apache2handler'))->servesTheWeb());
        $this->assertTrue((new ServerRuntime('frankenphp'))->servesTheWeb());
    }

    /**
     * ⚠️ ASKED OF THE RUNNING PHP WHEN NOTHING IS SAID, and the default matters more than it
     * looks: a seam left null in production that answered `false` would have the package stop
     * lifting its own time limit everywhere, silently, and the archives that stopped finishing
     * would be blamed on the storage.
     */
    public function test_it_asks_the_running_php_whether_the_time_limit_can_be_lifted(): void
    {
        $this->assertSame(
            function_exists('set_time_limit'),
            (new ServerRuntime('cli'))->canLiftTheTimeLimit(),
        );
    }

    /** ⚠️ AND A STATED ANSWER IS OBEYED, in both directions. */
    public function test_a_stated_answer_wins_over_the_running_php(): void
    {
        $this->assertFalse((new ServerRuntime('cli', false))->canLiftTheTimeLimit());
        $this->assertTrue((new ServerRuntime('cli', true))->canLiftTheTimeLimit());
    }

    public function test_it_reports_the_interface_it_was_given(): void
    {
        $this->assertSame('apache2handler', (new ServerRuntime('apache2handler'))->sapi());
    }
}
