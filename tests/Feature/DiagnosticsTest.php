<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kryption\MediaHub\Actions\DiagnoseSetup;
use Kryption\MediaHub\Support\ArchiveCapacity;
use Kryption\MediaHub\Support\RuntimeLimits;
use Kryption\MediaHub\Support\ServerRuntime;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE HEALTH REPORT — what the configuration promises against what the machine will do.
 *
 * ⚠️ THE CHECKS ARE DRIVEN FROM THE CONFIGURATION SIDE, NEVER BY CHANGING `php.ini`.
 * `post_max_size` and `upload_max_filesize` are `PHP_INI_PERDIR`: a test that tried to set them
 * at runtime would silently fail to, and then pass for the wrong reason on every machine. Asking
 * for a ceiling no PHP anywhere allows produces the same disagreement, and it produces it
 * identically in CI and on a laptop.
 */
class DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ HELD IN A PROPERTY RATHER THAN SET ON THE CONFIGURATION. The routes are registered at
     * boot, so the only way to see them absent is to build the application again — and building
     * it again runs this method again, which would put the flag straight back. What the test
     * changes has to be what this method reads.
     */
    private static bool $reportEnabled = true;

    protected function setUp(): void
    {
        self::$reportEnabled = true;

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mediahub.routes.middleware', ['web']);
        $app['config']->set('mediahub.diagnostics.enabled', self::$reportEnabled);
    }

    private function report(): array
    {
        return $this->app->make(DiagnoseSetup::class)();
    }

    private function check(string $id): array
    {
        foreach ($this->report()['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail('No check called '.$id.' in the report.');
    }

    // ── The door ─────────────────────────────────────────────────────────────

    /**
     * ⚠️ NOT REGISTERED WHEN THE FLAG IS OFF, rather than registered and refusing. A door that
     * answers "403" tells anybody asking that there is something behind it; one that is not
     * there says nothing at all.
     */
    public function test_the_report_is_not_reachable_unless_it_was_asked_for(): void
    {
        self::$reportEnabled = false;

        /* The routes are registered at boot, so the application has to be built again. */
        $this->refreshApplication();

        /*
         * ⚠️ THE ROUTE TABLE, NOT A REQUEST, AND THE DIFFERENCE IS THE WHOLE ASSERTION. Asking
         * for the address instead answers 404 either way: with the route gone, `GET {media}`
         * catches "diagnostics", tries to resolve it as a file, and fails to find one. Two very
         * different states, one status code — and the bench would have passed with the flag
         * doing nothing at all.
         */
        $this->assertFalse($this->app['router']->has('mediahub.diagnostics'));
    }

    public function test_the_route_exists_when_it_was_asked_for(): void
    {
        $this->assertTrue($this->app['router']->has('mediahub.diagnostics'));
    }

    public function test_the_report_is_served_when_it_was(): void
    {
        $body = $this->getJson('/media/diagnostics')->assertOk()->json('data');

        $this->assertArrayHasKey('ok', $body);
        $this->assertNotEmpty($body['checks']);
    }

    // ── Every word on the screen ─────────────────────────────────────────────

    /**
     * ⚠️ A MISSING TRANSLATION IS NOT AN ERROR IN LARAVEL, IT IS THE KEY. So a report whose
     * catalogue is shaped wrong renders perfectly and shows
     * `mediahub::diagnostics.uploads.post_max_size.title` to somebody trying to configure a
     * server — which is what shipped, and what a real screen caught.
     *
     * ⚠️ AND THE BENCH THAT SHOULD HAVE CAUGHT IT ASSERTED THE KEY BY ACCIDENT. It looked for
     * the directive's name inside the title, and the untranslated key contains it: the
     * assertion passed on the failure it was written to prevent. Checking that nothing still
     * looks like a key is the assertion that cannot be satisfied by one.
     *
     * ⚠️ IT WALKS THE WHOLE REPORT rather than a chosen finding, so a check added later is
     * covered on the day it is written.
     */
    public function test_nothing_in_the_report_is_still_a_key(): void
    {
        /* Every level is made to appear at once, so the wording of each is looked at. */
        $this->app['config']->set('mediahub.uploads.max_size', 100 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.max_bytes', 8 * 1024 * 1024 * 1024);

        foreach ($this->report()['checks'] as $check) {
            foreach (['title', 'detail', 'recommendation'] as $part) {
                $this->assertStringNotContainsString(
                    'mediahub::',
                    (string) $check[$part],
                    $check['id'].'.'.$part.' was never translated.',
                );
            }
        }
    }

    /** ⚠️ AND IN THE OTHER LANGUAGE TOO, since a catalogue can be right in one and shaped wrong
     * in the next. */
    public function test_nothing_in_the_french_report_is_still_a_key(): void
    {
        $this->app->setLocale('fr');
        $this->app['config']->set('mediahub.uploads.max_size', 100 * 1024 * 1024);

        foreach ($this->report()['checks'] as $check) {
            $this->assertStringNotContainsString('mediahub::', (string) $check['title']);
            $this->assertStringNotContainsString('mediahub::', (string) $check['detail']);
        }
    }

    /** ⚠️ NOR IS A PLACEHOLDER LEFT STANDING. `:allowed` on the screen means the sentence was
     * found and the value was not. */
    public function test_no_placeholder_survives_into_the_report(): void
    {
        $this->app['config']->set('mediahub.uploads.max_size', 100 * 1024 * 1024);

        foreach ($this->report()['checks'] as $check) {
            foreach (['title', 'detail', 'recommendation'] as $part) {
                $this->assertDoesNotMatchRegularExpression(
                    '/:[a-z_]+\b/',
                    (string) $check[$part],
                    $check['id'].'.'.$part.' still carries a placeholder.',
                );
            }
        }
    }

    // ── Uploads ──────────────────────────────────────────────────────────────

    /**
     * ⚠️ THE CEILING NOBODY CAN HONOUR IS THE ONE WORTH REPORTING. A library configured to accept
     * a hundred gigabytes accepts whatever PHP allows and refuses the rest before a single line
     * of this package runs — with an empty body and no reason, which is the one bug report
     * nobody can act on.
     */
    public function test_it_reports_an_upload_ceiling_php_will_not_honour(): void
    {
        $this->app['config']->set('mediahub.uploads.max_size', 100 * 1024 * 1024);

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $check = $this->check('uploads.'.$directive);

            $this->assertSame(DiagnoseSetup::FAILING, $check['level']);

            /*
             * ⚠️ A SENTENCE, NOT MERELY THE DIRECTIVE'S NAME. Looking for `post_max_size` alone
             * was satisfied by the untranslated key — which is literally
             * `mediahub::diagnostics.uploads.post_max_size.title` — so this bench passed on the
             * exact failure it existed to prevent, and the report shipped showing keys.
             */
            $this->assertStringContainsString($directive, $check['title']);
            $this->assertStringContainsString('PHP', $check['title']);

            /* ⚠️ AND IT SAYS WHAT TO DO, which is the half that asks somebody to act. */
            $this->assertStringContainsString($directive, (string) $check['recommendation']);
            $this->assertStringContainsString('php.ini', (string) $check['recommendation']);
        }
    }

    public function test_it_says_nothing_about_an_upload_ceiling_php_allows(): void
    {
        $this->app['config']->set('mediahub.uploads.max_size', 1);

        $this->assertSame(DiagnoseSetup::FINE, $this->check('uploads.post_max_size')['level']);
        $this->assertNull($this->check('uploads.post_max_size')['recommendation']);
    }

    /** ⚠️ A FAILING CHECK MAKES THE WHOLE REPORT FAIL, or the summary at the top is decoration. */
    public function test_one_failure_is_enough_to_fail_the_report(): void
    {
        $this->app['config']->set('mediahub.uploads.max_size', 100 * 1024 * 1024);

        $this->assertFalse($this->report()['ok']);
    }

    // ── Archives ─────────────────────────────────────────────────────────────

    /**
     * ⚠️ WHAT IS REPORTED IS THAT THE NUMBER IN THE CONFIGURATION IS NOT THE NUMBER IN FORCE.
     * Two gigabytes allowed on a machine believed able to finish six hundred megabytes is not a
     * fault yet — the package already refuses beyond what it can deliver — but it is a promise
     * nobody is keeping, and the person who wrote it has no other way to find out.
     */
    public function test_it_reports_an_archive_ceiling_beyond_what_the_machine_can_finish(): void
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 8 * 1024 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.time_budget', 0);

        $check = $this->check('archives.capacity');

        $this->assertSame(DiagnoseSetup::RISKY, $check['level']);

        /*
         * ⚠️ AND WITH NOTHING DECLARED, THE ADVICE IS TO DECLARE — not to lower a number that may
         * be perfectly reachable on a machine whose timeouts nobody has told us about.
         *
         * ⚠️ WHAT IT MUST NOT MENTION IS THE OTHER FIX, and that is the assertion. Both pieces of
         * advice name `time_budget`, one to ask for it and one to offer raising it, so looking
         * for that word answers yes to either: the mutation that always gave the wrong one
         * stayed green. Only the second names `max_bytes`.
         */
        $this->assertStringContainsString('time_budget', (string) $check['recommendation']);
        $this->assertStringNotContainsString('max_bytes', (string) $check['recommendation']);
    }

    public function test_a_declared_budget_settles_it(): void
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 100 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.time_budget', 600);

        $this->assertSame(DiagnoseSetup::FINE, $this->check('archives.capacity')['level']);
    }

    /** ⚠️ AND A DECLARED BUDGET THAT IS STILL TOO SMALL ASKS FOR THE OTHER FIX. */
    public function test_a_declared_budget_that_is_too_small_asks_for_a_lower_ceiling(): void
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 8 * 1024 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.time_budget', 30);

        $this->assertStringContainsString(
            'max_bytes',
            (string) $this->check('archives.capacity')['recommendation'],
        );
    }

    // ── Extensions ───────────────────────────────────────────────────────────

    public function test_it_looks_for_the_extensions_it_needs(): void
    {
        $this->assertSame(DiagnoseSetup::FINE, $this->check('extensions.zip')['level']);
        $this->assertSame(DiagnoseSetup::FINE, $this->check('extensions.fileinfo')['level']);
    }

    /**
     * ⚠️ "NONE" IS A NORMAL STATE, and reporting a missing image library as a fault on a host
     * that stores documents is how a report teaches people to stop opening it.
     */
    public function test_no_image_driver_is_not_a_finding(): void
    {
        $this->app['config']->set('mediahub.images.driver', 'none');

        /*
         * ⚠️ NO IMAGE CHECK AT ALL, RATHER THAN "NOT GD AND NOT IMAGICK". Naming the two drivers
         * lets a rule that blindly looks up whatever the setting says slip through: with the
         * driver set to "none" it reported on an extension called "none", which is neither of
         * the two names and is nonsense on the screen. The claim is that the image family is
         * absent from the report.
         */
        $extensions = array_filter(
            array_column($this->report()['checks'], 'id'),
            static fn (string $id): bool => str_starts_with($id, 'extensions.'),
        );

        $this->assertSame(['extensions.zip', 'extensions.fileinfo'], array_values($extensions));
    }

    // ── What the machine says ────────────────────────────────────────────────

    /**
     * ⚠️ `(int) '8M'` IS 8, NOT 8388608 — an error of six orders of magnitude that produces a
     * comparison which always passes. Every size directive read anywhere in this package goes
     * through the conversion this proves.
     */
    public function test_a_shorthand_size_is_read_as_a_size(): void
    {
        $limits = $this->app->make(RuntimeLimits::class);

        /* ⚠️ ABOVE WHAT IS ALREADY IN USE, NECESSARILY. PHP refuses to lower the limit under
         * current usage, and the refusal is a warning rather than a return value — a bench that
         * asked for eight megabytes would be reading whatever the limit was before. */
        ini_set('memory_limit', '512M');
        $this->assertSame(512 * 1024 * 1024, $limits->bytes('memory_limit'));

        ini_set('memory_limit', '1G');
        $this->assertSame(1024 * 1024 * 1024, $limits->bytes('memory_limit'));

        /* ⚠️ AND "UNLIMITED" IS NOT A HUGE NUMBER, it is the absence of one. PHP spells it `-1`
         * here and `0` elsewhere; both have to come back the same way, or every comparison
         * against the answer has to remember which directive it is looking at. */
        ini_set('memory_limit', '-1');
        $this->assertNull($limits->bytes('memory_limit'));
    }

    /**
     * ⚠️ HOW MUCH ROOM IS LEFT, NOT HOW MUCH THERE IS. A limit of 256 MB with 200 already in use
     * is a 56 MB budget, and a check against the limit itself passes on its way to an
     * exhaustion.
     */
    public function test_the_memory_left_is_less_than_the_limit(): void
    {
        $limits = $this->app->make(RuntimeLimits::class);

        ini_set('memory_limit', '256M');

        $left = $limits->memoryLeft();

        $this->assertNotNull($left);
        $this->assertLessThan(256 * 1024 * 1024, $left);
    }

    /**
     * ⚠️ "NO LIMIT" IN THE CONFIGURATION HAS NEVER MEANT "THE MACHINE CAN SEND ANYTHING". It
     * means the package imposes none of its own, and reading it as infinity is what lets a
     * two-hour archive start.
     */
    public function test_an_unlimited_configuration_is_still_bounded_by_the_machine(): void
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 0);
        $this->app['config']->set('mediahub.archives.time_budget', 60);
        $this->app['config']->set('mediahub.archives.throughput', 1024);

        $capacity = $this->app->make(ArchiveCapacity::class);

        $this->assertSame(60 * 1024, $capacity->effectiveCeiling());
    }

    /** ⚠️ AND THE SMALLER OF THE TWO WINS, whichever it is. */
    public function test_the_smaller_of_the_two_ceilings_wins(): void
    {
        $this->app['config']->set('mediahub.archives.time_budget', 60);
        $this->app['config']->set('mediahub.archives.throughput', 1024);

        $this->app['config']->set('mediahub.archives.max_bytes', 10);
        $this->assertSame(10, $this->app->make(ArchiveCapacity::class)->effectiveCeiling());

        $this->app['config']->set('mediahub.archives.max_bytes', 999_999_999);
        $this->assertSame(60 * 1024, $this->app->make(ArchiveCapacity::class)->effectiveCeiling());
    }

    // ── Which PHP is answering ───────────────────────────────────────────────

    /**
     * ⚠️ THE RUNTIME IS SUBSTITUTED, NOT SIMULATED. `PHP_SAPI` is a compile-time constant and
     * `disable_functions` is `PHP_INI_SYSTEM`: neither can be produced from inside a test, so
     * without a seam the only runtime ever exercised would be the console — the one family whose
     * advice matters least, and the one every other family's wording differs from.
     */
    private function standingIn(string $sapi, ?bool $canLift = null): void
    {
        $this->app->instance(ServerRuntime::class, new ServerRuntime($sapi, $canLift));
    }

    /** A ceiling above what the machine is thought able to finish, with nothing declared. */
    private function adviceOnCapacity(): string
    {
        $this->app['config']->set('mediahub.archives.max_bytes', 8 * 1024 * 1024 * 1024);
        $this->app['config']->set('mediahub.archives.time_budget', 0);

        return (string) $this->check('archives.capacity')['recommendation'];
    }

    public function test_a_pooled_php_is_sent_to_its_pool_manager(): void
    {
        $this->standingIn('fpm-fcgi');

        $this->assertStringContainsString('request_terminate_timeout', $this->adviceOnCapacity());
    }

    /**
     * ⚠️ AND MOD_PHP IS NOT SENT THERE, WHICH IS THE WHOLE POINT OF THE LOT. There is no
     * `php-fpm.conf` on such a machine and no `request_terminate_timeout` in it: somebody
     * follows the advice, finds neither, and concludes the report is describing a different
     * product. Advice that names the wrong file is worse than none.
     */
    public function test_an_embedded_php_is_not_sent_to_a_pool_it_does_not_have(): void
    {
        $this->standingIn('apache2handler');

        $advice = $this->adviceOnCapacity();

        $this->assertStringNotContainsString('request_terminate_timeout', $advice);
        $this->assertStringNotContainsString('PHP-FPM', $advice);

        /* ⚠️ AND IT STILL SAYS SOMETHING USEFUL. Removing the wrong sentence is only half of it;
         * a report that answers "it depends" has cost somebody the click. */
        $this->assertStringContainsString('mod_php', $advice);
    }

    public function test_a_gateway_php_is_sent_to_whatever_speaks_fastcgi_to_it(): void
    {
        $this->standingIn('cgi-fcgi');

        $advice = $this->adviceOnCapacity();

        /*
         * ⚠️ `cgi-fcgi` IS NOT PHP-FPM, AND THE NAME INVITES THE MISTAKE. Matching interfaces on
         * substrings would file plain FastCGI under the pool manager and hand it a
         * `php-fpm.conf` that is not there — so the two are asserted apart rather than merely
         * one of them asserted present.
         */
        $this->assertStringContainsString('fastcgi_read_timeout', $advice);
        $this->assertStringNotContainsString('request_terminate_timeout', $advice);
    }

    /**
     * ⚠️ WHAT IS NOT RECOGNISED IS SAID TO BE UNRECOGNISED. LiteSpeed, FrankenPHP and whatever
     * comes next each bound a request their own way; guessing at one of them produces exactly
     * the confident-but-wrong sentence this whole check exists to prevent.
     */
    public function test_an_unrecognised_runtime_is_sent_to_no_file_at_all(): void
    {
        $this->standingIn('frankenphp');

        $advice = $this->adviceOnCapacity();

        foreach (['request_terminate_timeout', 'fastcgi_read_timeout', 'mod_php', 'php-fpm'] as $named) {
            $this->assertStringNotContainsString($named, $advice, 'A runtime nobody recognises was sent to '.$named.'.');
        }

        $this->assertStringContainsString('time_budget', $advice);
    }

    /**
     * ⚠️ A REPORT PRODUCED FROM THE CONSOLE DESCRIBES THE CONSOLE. Its memory limit, its
     * execution time and its extensions are the command line's, and a separate `php.ini` for it
     * is the normal arrangement rather than an exotic one. Read as a verdict on the server, such
     * a report is confidently wrong about every number in it — so it says so about itself.
     */
    public function test_a_report_produced_from_the_console_says_which_machine_it_describes(): void
    {
        $this->standingIn('cli');

        $check = $this->check('runtime.sapi');

        $this->assertSame(DiagnoseSetup::RISKY, $check['level']);
        $this->assertNotNull($check['recommendation']);
    }

    public function test_a_report_produced_by_the_runtime_that_serves_the_site_is_not_a_warning(): void
    {
        $this->standingIn('fpm-fcgi');

        $check = $this->check('runtime.sapi');

        $this->assertSame(DiagnoseSetup::FINE, $check['level']);
        $this->assertNull($check['recommendation']);

        /* ⚠️ THE INTERFACE IS NAMED, and the name cannot come from an untranslated key — no key
         * in this catalogue contains `fpm-fcgi`, which is what makes the assertion worth
         * writing after a report once shipped showing keys. */
        $this->assertStringContainsString('fpm-fcgi', $check['title']);
    }

    /**
     * ⚠️ ONE PHRASE PER FAMILY, AND A MISSING ONE IS NOT AN ERROR IN LARAVEL — IT IS THE KEY, on
     * screen, in a sentence telling somebody how to configure their server. The families are
     * walked rather than sampled because the one that is wrong is always the one nobody tried.
     */
    public function test_every_runtime_family_has_wording_in_both_languages(): void
    {
        foreach (['fpm-fcgi', 'apache2handler', 'cgi-fcgi', 'cli', 'frankenphp'] as $sapi) {
            foreach (['en', 'fr'] as $locale) {
                $this->app->setLocale($locale);
                $this->standingIn($sapi);
                $this->app['config']->set('mediahub.archives.max_bytes', 8 * 1024 * 1024 * 1024);

                foreach ($this->report()['checks'] as $check) {
                    foreach (['title', 'detail', 'recommendation'] as $part) {
                        $where = $sapi.'/'.$locale.' '.$check['id'].'.'.$part;

                        $this->assertStringNotContainsString('mediahub::', (string) $check[$part], $where.' was never translated.');
                        $this->assertDoesNotMatchRegularExpression('/:[a-z_]+\b/', (string) $check[$part], $where.' still carries a placeholder.');
                    }
                }
            }
        }
    }

    // ── The time an archive may spend compressing ────────────────────────────

    /**
     * ⚠️ THE LIMIT IS RESTORED, because arming it leaves it armed. A bench that sets thirty
     * seconds and walks away has armed the timer for every test that follows, and the suite then
     * dies somewhere else entirely — which reads as a flaky test rather than as this one.
     */
    protected function tearDown(): void
    {
        ini_set('max_execution_time', '0');

        parent::tearDown();
    }

    /**
     * ⚠️ THE ONE CEILING A SHARED HOST ACTUALLY HITS. Waiting on storage does not count against
     * `max_execution_time` — measured: a script blocked on a pipe outlived a two-second limit by
     * fifteen, while the same limit killed a busy loop at 2.1 — but compressing does. Deflating
     * a few gigabytes is processor work, and where `set_time_limit` has been taken away the
     * package cannot buy itself the time to finish.
     */
    public function test_a_time_limit_the_package_cannot_lift_is_reported(): void
    {
        $this->standingIn('apache2handler', canLift: false);
        ini_set('max_execution_time', '30');

        $check = $this->check('archives.execution_time');

        $this->assertSame(DiagnoseSetup::RISKY, $check['level']);
        $this->assertStringContainsString('30', $check['detail']);
        $this->assertStringContainsString('set_time_limit', (string) $check['recommendation']);
    }

    /** ⚠️ AND WHERE IT CAN BE LIFTED, THERE IS NOTHING TO REPORT — the package simply lifts it. */
    public function test_a_time_limit_the_package_can_lift_is_not_a_finding(): void
    {
        $this->standingIn('apache2handler', canLift: true);
        ini_set('max_execution_time', '30');

        $check = $this->check('archives.execution_time');

        $this->assertSame(DiagnoseSetup::FINE, $check['level']);
        $this->assertNull($check['recommendation']);
    }

    /** ⚠️ NOR WHERE THERE IS NO LIMIT AT ALL, even with the function gone. */
    public function test_no_time_limit_at_all_is_not_a_finding(): void
    {
        $this->standingIn('apache2handler', canLift: false);
        ini_set('max_execution_time', '0');

        $this->assertSame(DiagnoseSetup::FINE, $this->check('archives.execution_time')['level']);
    }
}
