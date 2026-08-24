<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kryption\MediaHub\Support\DefaultPathGenerator;

/**
 * THE PATH IS GIVEN, NOT DECIDED.
 *
 * ⚠️ THIS FILE HOLDS A NEGATIVE PROPERTY, AND IT IS THE PACKAGE'S MOST IMPORTANT: the factory
 * invents no tree. It takes the folder the caller gives it, sanitises it, and stops there. How
 * media are organised belongs to the trade of whoever installs it: an agency files by client and
 * campaign, an intranet by department. Imposing a tree would amount to imposing a trade.
 *
 * What remains here is what everybody needs and nobody wants to write again: path traversal
 * closed off, and the derivative that follows its original.
 */
class DefaultPathGeneratorTest extends TestCase
{
    private DefaultPathGenerator $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paths = new DefaultPathGenerator();
    }

    // ── What the caller gives, the caller gets back ──────────────────────────

    /** ⚠️ THE TEST THAT COUNTS: the folder asked for is the folder returned. */
    public function test_the_requested_folder_is_returned_as_it_is(): void
    {
        $this->assertSame(
            'clients/durand/winter-campaign/',
            $this->paths->directory(['directory' => 'clients/durand/winter-campaign'])
        );
    }

    /** Another company, another way of filing — and the same factory. */
    public function test_another_tree_is_just_as_valid(): void
    {
        $this->assertSame(
            '2026/08/invoices/',
            $this->paths->directory(['directory' => '2026/08/invoices'])
        );
    }

    public function test_without_a_folder_we_write_at_the_root(): void
    {
        $this->assertSame('', $this->paths->directory([]));
        $this->assertSame('', $this->paths->directory(['directory' => '']));
    }

    /** A shared leading folder, if the host wants one — and it comes FIRST. */
    public function test_a_shared_prefix_is_placed_in_front(): void
    {
        $paths = new DefaultPathGenerator('media');

        $this->assertSame('media/invoices/', $paths->directory(['directory' => 'invoices']));
        $this->assertSame('media/', $paths->directory([]));
    }

    /**
     * ⚠️ WE SANITISE WITHOUT TRANSFORMING. Accents and capitals are kept: normalising beyond
     * what is necessary would impose a taste, and would make the requested path unpredictable.
     */
    public function test_accents_and_capitals_are_kept(): void
    {
        $this->assertSame(
            'Meeting Notes/Réunion/',
            $this->paths->directory(['directory' => 'Meeting Notes/Réunion'])
        );
    }

    public function test_empty_segments_disappear(): void
    {
        $this->assertSame('a/b/', $this->paths->directory(['directory' => '/a//b/']));
    }

    // ── Path traversal ───────────────────────────────────────────────────────

    /** ⚠️ NO SEGMENT CLIMBS: traversal is closed here, and nowhere else. */
    public function test_a_folder_cannot_climb(): void
    {
        $this->assertSame(
            'etc/passwd/',
            $this->paths->directory(['directory' => '../../../etc/passwd'])
        );
    }

    public function test_a_backslash_does_not_traverse(): void
    {
        $this->assertSame(
            'windows/system/',
            $this->paths->directory(['directory' => '..\\windows\\system'])
        );
    }

    public function test_a_null_byte_disappears(): void
    {
        $this->assertSame('invoices/', $this->paths->directory(['directory' => "invo\0ices"]));
    }

    public function test_a_segment_made_only_of_dots_disappears(): void
    {
        $this->assertSame('a/b/', $this->paths->directory(['directory' => 'a/.../b']));
    }

    // ── Derivatives ──────────────────────────────────────────────────────────

    public function test_a_derivative_is_filed_next_to_its_original(): void
    {
        $this->assertSame(
            'invoices/photo-thumb.jpg',
            $this->paths->conversion('invoices/photo.jpg', 'thumb')
        );
    }

    public function test_a_derivative_of_a_file_without_an_extension_is_still_suffixed(): void
    {
        $this->assertSame('invoices/photo-thumb', $this->paths->conversion('invoices/photo', 'thumb'));
    }

    public function test_a_derivative_without_a_name_changes_nothing(): void
    {
        $this->assertSame('invoices/photo.jpg', $this->paths->conversion('invoices/photo.jpg', ''));
    }

    /** A name with several dots does not lose its real extension. */
    public function test_a_name_with_several_dots_keeps_its_last_extension(): void
    {
        $this->assertSame(
            'invoices/report.v2-thumb.pdf',
            $this->paths->conversion('invoices/report.v2.pdf', 'thumb')
        );
    }
}
