<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Support\SluggedFileNamer;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE NAME ON DISK.
 *
 * ⚠️ THIS FILE EXISTS FOR ONE PRECISE DEFECT. The module this package replaces checked for
 * collisions with a LOCAL filesystem function, while its objects lived on remote storage: the
 * check never detected anything, and two uploads of the same name into the same folder silently
 * overwrote the first object — while creating two rows naming the same path.
 *
 * So uniqueness is checked **against the storage**, and this test proves it by putting an object
 * there.
 */
class SluggedFileNamerTest extends TestCase
{
    private SluggedFileNamer $namer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');

        $this->namer = new SluggedFileNamer($this->app['filesystem']);
    }

    // ── Normalising ──────────────────────────────────────────────────────────

    public function test_a_human_name_is_normalised(): void
    {
        $this->assertSame('annual-report-2026', $this->namer->sanitize('Annual Report 2026.pdf'));
    }

    public function test_accents_disappear(): void
    {
        $this->assertSame('reunion-dequipe', $this->namer->sanitize('Réunion d’équipe.docx'));
    }

    /**
     * ⚠️ A NAME WITH NOTHING TO NORMALISE DOES NOT PRODUCE AN EMPTY NAME. Two production files
     * are called `…272552.` — a trailing dot and nothing behind: they cannot be found, and never
     * will be.
     */
    public function test_a_name_with_nothing_to_normalise_stays_usable(): void
    {
        $this->assertSame('media', $this->namer->sanitize('...'));
        $this->assertSame('media', $this->namer->sanitize(''));
    }

    /** The original name traverses no folder: only its last part is kept. */
    public function test_the_name_does_not_traverse(): void
    {
        $this->assertSame('passwd', $this->namer->sanitize('../../../etc/passwd'));
    }

    // ── Uniqueness, checked against the storage ──────────────────────────────

    public function test_a_free_name_is_returned_as_it_is(): void
    {
        $this->assertSame('report.pdf', $this->namer->unique('report.pdf', 'media', 'orgs/7/library'));
    }

    /** ⚠️ THE TEST THAT COUNTS: the object EXISTS on the disk, and the name must change. */
    public function test_a_name_already_taken_on_the_storage_is_suffixed(): void
    {
        Storage::disk('media')->put('orgs/7/library/report.pdf', 'x');

        $this->assertSame('report-1.pdf', $this->namer->unique('report.pdf', 'media', 'orgs/7/library'));
    }

    public function test_the_suffixes_follow_on(): void
    {
        Storage::disk('media')->put('orgs/7/library/report.pdf', 'x');
        Storage::disk('media')->put('orgs/7/library/report-1.pdf', 'x');
        Storage::disk('media')->put('orgs/7/library/report-2.pdf', 'x');

        $this->assertSame('report-3.pdf', $this->namer->unique('report.pdf', 'media', 'orgs/7/library'));
    }

    /** ⚠️ A NAMESAKE IN ANOTHER FOLDER IS NOT ONE. */
    public function test_a_namesake_in_another_folder_does_not_get_in_the_way(): void
    {
        Storage::disk('media')->put('orgs/7/library/report.pdf', 'x');

        $this->assertSame('report.pdf', $this->namer->unique('report.pdf', 'media', 'orgs/7/comments'));
    }

    public function test_a_file_without_an_extension_is_handled(): void
    {
        Storage::disk('media')->put('orgs/7/library/notes', 'x');

        $this->assertSame('notes-1', $this->namer->unique('notes', 'media', 'orgs/7/library'));
    }

    public function test_the_disk_root_is_a_folder_like_any_other(): void
    {
        Storage::disk('media')->put('report.pdf', 'x');

        $this->assertSame('report-1.pdf', $this->namer->unique('report.pdf', 'media', ''));
    }

    /**
     * ⚠️ BEYOND FIFTY NAMESAKES, WE CUT RATHER THAN REFUSE. Fifty files with the same name in
     * one folder is already an anomaly; refusing the upload at that point would punish the
     * person for a mess that is not theirs.
     */
    public function test_beyond_the_bound_an_unpredictable_suffix_settles_it(): void
    {
        Storage::disk('media')->put('orgs/7/library/report.pdf', 'x');
        for ($i = 1; $i <= 50; $i++) {
            Storage::disk('media')->put('orgs/7/library/report-'.$i.'.pdf', 'x');
        }

        $name = $this->namer->unique('report.pdf', 'media', 'orgs/7/library');

        $this->assertMatchesRegularExpression('/^report-[a-z0-9]{8}\.pdf$/', $name);
        $this->assertFalse(Storage::disk('media')->exists('orgs/7/library/'.$name));
    }
}
