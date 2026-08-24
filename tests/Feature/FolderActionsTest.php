<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Actions\MoveFolder;
use Kryption\MediaHub\Actions\RenameFolder;
use Kryption\MediaHub\Events\FolderCreated;
use Kryption\MediaHub\Events\FolderMoved;
use Kryption\MediaHub\Events\FolderRenamed;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\FolderTree;
use Kryption\MediaHub\Tests\TestCase;

/**
 * THE TREE — and above all what breaks it without anything falling over.
 *
 * ⚠️ THIS FILE TESTS TWO THINGS OF DIFFERENT NATURES, and they need telling apart: what the tree
 * DOES (create, rename, move), and what it REFUSES to do. The refusals are the half that counts:
 * a folder that became its own ancestor raises no exception, writes no log, and simply makes a
 * whole branch disappear from the only place anyone looks for it.
 */
class FolderActionsTest extends TestCase
{
    use RefreshDatabase;

    private function create(): CreateFolder
    {
        return $this->app->make(CreateFolder::class);
    }

    private function rename(): RenameFolder
    {
        return $this->app->make(RenameFolder::class);
    }

    private function move(): MoveFolder
    {
        return $this->app->make(MoveFolder::class);
    }

    // ── Creating ─────────────────────────────────────────────────────────────

    public function test_a_folder_is_born_with_its_path_and_its_depth(): void
    {
        $root = ($this->create())('Photos');
        $child = ($this->create())('Holidays', $root);

        $this->assertSame('photos', $root->path);
        $this->assertSame(0, $root->depth);

        $this->assertSame('photos/holidays', $child->path);
        $this->assertSame(1, $child->depth);
        $this->assertSame($root->getKey(), $child->parent_id);
    }

    public function test_the_displayed_name_is_kept_as_it_is(): void
    {
        /*
         * ⚠️ THE NAME AND THE SLUG ARE TWO DIFFERENT THINGS. The first is what the person wrote,
         * the second is what builds a path. Confusing them lets accents, spaces and separators
         * climb into a storage path.
         */
        $folder = ($this->create())('  Été 2026 / drafts  ');

        $this->assertSame('Été 2026 / drafts', $folder->name);
        $this->assertSame('ete-2026-drafts', $folder->slug);
    }

    public function test_two_folders_with_the_same_name_in_one_place_get_distinct_slugs(): void
    {
        $first = ($this->create())('Photos');
        $second = ($this->create())('Photos');
        $third = ($this->create())('Photos');

        $this->assertSame('photos', $first->slug);
        $this->assertSame('photos-2', $second->slug);
        $this->assertSame('photos-3', $third->slug);
    }

    public function test_the_same_name_is_free_under_another_parent(): void
    {
        $a = ($this->create())('Client A');
        $b = ($this->create())('Client B');

        $this->assertSame('invoices', ($this->create())('Invoices', $a)->slug);
        $this->assertSame('invoices', ($this->create())('Invoices', $b)->slug);
    }

    public function test_a_trashed_sibling_still_occupies_its_slug(): void
    {
        /*
         * ⚠️ OTHERWISE THE CONFLICT ONLY SURFACES AT RESTORE TIME, that is, weeks after its
         * cause — and it produces two folders with the same path, which nothing on screen tells
         * apart any more.
         */
        ($this->create())('Photos')->delete();

        $this->assertSame('photos-2', ($this->create())('Photos')->slug);
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->expectException(OperationRejected::class);

        ($this->create())('   ');
    }

    public function test_a_name_with_no_sluggable_character_can_still_be_created(): void
    {
        /*
         * ⚠️ A NAME THAT PRODUCES NO SLUG IS NOT AN EMPTY NAME. Ideograms, punctuation alone:
         * the slug falls to empty, and without a fallback the folder's materialised path would
         * be its parent's — two folders for one path.
         */
        $folder = ($this->create())('★★★');

        $this->assertNotSame('', $folder->slug);
        $this->assertSame('★★★', $folder->name);
    }

    public function test_a_folder_that_is_too_deep_is_refused(): void
    {
        $parent = ($this->create())('Root');
        $parent->depth = FolderTree::MAX_DEPTH - 1;
        $parent->save();

        $this->expectException(OperationRejected::class);

        ($this->create())('One too many', $parent);
    }

    // ── Renaming ─────────────────────────────────────────────────────────────

    public function test_renaming_rewrites_the_path_of_every_descendant(): void
    {
        $root = ($this->create())('Photos');
        $child = ($this->create())('Holidays', $root);
        $grandchild = ($this->create())('Corsica', $child);

        ($this->rename())($root, 'Images');

        $this->assertSame('images', $root->fresh()->path);
        $this->assertSame('images/holidays', $child->fresh()->path);
        $this->assertSame('images/holidays/corsica', $grandchild->fresh()->path);
    }

    public function test_renaming_moves_no_byte(): void
    {
        $folder = ($this->create())('Photos');
        $media = $this->media($folder);

        ($this->rename())($folder, 'Images');

        $this->assertSame('2026/08/report.pdf', $media->fresh()->path);
        $this->assertSame('report.pdf', $media->fresh()->file_name);
    }

    public function test_a_trashed_descendant_has_its_path_rewritten_too(): void
    {
        $root = ($this->create())('Photos');
        $child = ($this->create())('Holidays', $root);
        $child->delete();

        ($this->rename())($root, 'Images');

        $this->assertSame(
            'images/holidays',
            MediaFolder::withTrashed()->find($child->getKey())->path
        );
    }

    // ── Moving ───────────────────────────────────────────────────────────────

    public function test_moving_rewrites_path_and_depth(): void
    {
        $source = ($this->create())('Source');
        $target = ($this->create())('Target');
        $child = ($this->create())('Leaf', $source);

        ($this->move())($source, $target);

        $this->assertSame('target/source', $source->fresh()->path);
        $this->assertSame(1, $source->fresh()->depth);
        $this->assertSame('target/source/leaf', $child->fresh()->path);
        $this->assertSame(2, $child->fresh()->depth);
    }

    public function test_moving_to_the_root_is_possible(): void
    {
        $parent = ($this->create())('Parent');
        $child = ($this->create())('Child', $parent);

        ($this->move())($child, null);

        $this->assertNull($child->fresh()->parent_id);
        $this->assertSame('child', $child->fresh()->path);
        $this->assertSame(0, $child->fresh()->depth);
    }

    public function test_a_folder_cannot_become_its_own_parent(): void
    {
        $folder = ($this->create())('Photos');

        $this->expectException(OperationRejected::class);

        ($this->move())($folder, $folder);
    }

    public function test_a_folder_cannot_descend_into_its_own_descendants(): void
    {
        $root = ($this->create())('Root');
        $child = ($this->create())('Child', $root);
        $grandchild = ($this->create())('Grandchild', $child);

        $this->expectException(OperationRejected::class);

        ($this->move())($root, $grandchild);
    }

    public function test_a_refused_cycle_leaves_nothing_behind(): void
    {
        /*
         * ⚠️ REFUSING IS NOT ENOUGH: IT HAS TO REFUSE BEFORE WRITING. A check placed after the
         * first write would leave `parent_id` changed and the original path — exactly the
         * inconsistency it claims to prevent.
         */
        $root = ($this->create())('Root');
        $child = ($this->create())('Child', $root);

        try {
            ($this->move())($root, $child);
        } catch (OperationRejected) {
            // expected
        }

        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame('root', $root->fresh()->path);
        $this->assertSame('root/child', $child->fresh()->path);
    }

    public function test_the_slug_is_checked_again_under_the_new_parent(): void
    {
        $target = ($this->create())('Target');
        ($this->create())('Photos', $target);

        $traveller = ($this->create())('Photos');

        ($this->move())($traveller, $target);

        $this->assertSame('photos-2', $traveller->fresh()->slug);
        $this->assertSame('target/photos-2', $traveller->fresh()->path);
    }

    // ── The tree itself ──────────────────────────────────────────────────────

    public function test_the_breadcrumb_trail_goes_from_the_root_to_the_folder(): void
    {
        $root = ($this->create())('Root');
        $child = ($this->create())('Child', $root);
        $grandchild = ($this->create())('Grandchild', $child);

        $trail = $this->app->make(FolderTree::class)->breadcrumbs($grandchild);

        $this->assertSame(['Root', 'Child', 'Grandchild'], $trail->pluck('name')->all());
    }

    public function test_the_breadcrumb_trail_crosses_a_trashed_ancestor(): void
    {
        /*
         * ⚠️ WITHOUT `withTrashed()` THE CLIMB STOPS AT THE FIRST DELETED ANCESTOR, and the
         * trail returned starts in the middle of nowhere — which reads as a root folder, not as
         * a branch in the trash.
         */
        $root = ($this->create())('Root');
        $child = ($this->create())('Child', $root);
        $root->delete();

        $trail = $this->app->make(FolderTree::class)->breadcrumbs($child->fresh());

        $this->assertSame(['Root', 'Child'], $trail->pluck('name')->all());
    }

    public function test_a_circular_tree_does_not_spin_the_climb(): void
    {
        /*
         * ⚠️ THIS CASE CANNOT BE PRODUCED BY THE ACTIONS — they refuse it. It happens through a
         * data migration, a concurrent write or an import: it is corrupted data, not a code bug,
         * and the guard is not to prevent it but not to die on it.
         */
        $a = ($this->create())('A');
        $b = ($this->create())('B', $a);

        MediaFolder::withoutMediaScope()->whereKey($a->getKey())->update(['parent_id' => $b->getKey()]);

        $trail = $this->app->make(FolderTree::class)->breadcrumbs($b->fresh());

        $this->assertLessThanOrEqual(FolderTree::MAX_DEPTH, $trail->count());
    }

    // ── Events ───────────────────────────────────────────────────────────────

    public function test_every_action_emits_its_event(): void
    {
        Event::fake([FolderCreated::class, FolderRenamed::class, FolderMoved::class]);

        $folder = ($this->create())('Photos');
        $target = ($this->create())('Target');
        ($this->rename())($folder, 'Images');
        ($this->move())($folder, $target);

        Event::assertDispatched(FolderCreated::class, 2);
        Event::assertDispatched(FolderRenamed::class, 1);
        Event::assertDispatched(FolderMoved::class, 1);
    }

    public function test_renaming_with_an_empty_name_is_refused(): void
    {
        $folder = ($this->create())('Photos');

        $this->expectException(OperationRejected::class);

        ($this->rename())($folder, '  ');
    }

    public function test_moving_under_a_parent_that_is_too_deep_is_refused(): void
    {
        $folder = ($this->create())('Traveller');
        $parent = ($this->create())('Bottom');
        $parent->depth = FolderTree::MAX_DEPTH - 1;
        $parent->save();

        $this->expectException(OperationRejected::class);

        ($this->move())($folder, $parent);
    }

    // ── The transaction ──────────────────────────────────────────────────────

    public function test_rewriting_the_descendants_happens_inside_a_transaction(): void
    {
        /*
         * ⚠️ REWRITING A HUNDRED PATHS AND STOPPING AT THE FIFTIETH leaves a tree half of which
         * names a parent that no longer exists under that name — and nothing reports it: both
         * halves are individually valid.
         */
        $root = ($this->create())('Root');
        ($this->create())('Child', $root);

        $opened = 0;
        $this->app['events']->listen(TransactionBeginning::class, static function () use (&$opened): void {
            $opened++;
        });

        ($this->rename())($root, 'Images');

        $this->assertGreaterThan(0, $opened);
    }

    private function media(MediaFolder $folder): Media
    {
        return Media::create([
            'folder_id' => $folder->getKey(),
            'disk' => 'media',
            'path' => '2026/08/report.pdf',
            'name' => 'report',
            'file_name' => 'report.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 1024,
        ]);
    }
}
