<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kryption\MediaHub\Actions\DiagnoseSetup;
use Kryption\MediaHub\Support\ArchiveCapacity;
use Kryption\MediaHub\Support\RuntimeLimits;
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
            $this->assertStringContainsString($directive, $check['title']);

            /* ⚠️ AND IT SAYS WHAT TO DO, which is the half that asks somebody to act. */
            $this->assertStringContainsString($directive, (string) $check['recommendation']);
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
}
