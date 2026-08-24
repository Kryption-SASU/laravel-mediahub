<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Kryption\MediaHub\Actions\CreateFolder;
use Kryption\MediaHub\Actions\EmptyTrash;
use Kryption\MediaHub\Actions\ForceDeleteItems;
use Kryption\MediaHub\Actions\PruneTrash;
use Kryption\MediaHub\Actions\TrashItems;
use Kryption\MediaHub\Contracts\MediaScope;
use Kryption\MediaHub\Exceptions\ItemNotFound;
use Kryption\MediaHub\Models\Media;
use Kryption\MediaHub\Models\MediaFolder;
use Kryption\MediaHub\Support\SelectionResolver;
use Kryption\MediaHub\Tests\TestCase;
use Kryption\MediaHub\ValueObjects\ItemSelection;
use Kryption\MediaHub\ValueObjects\ResolvedItems;

/**
 * SCOPING THE ACTIONS — the flaw that made this package necessary, closed and guarded.
 *
 * ⚠️ THIS FILE EXISTS BECAUSE THE MODULE THIS PACKAGE REPLACES FILTERED ITS LISTINGS AND NOT ITS
 * ACTIONS. Its single catch-all endpoint worked on the identifiers received from the client, as
 * they were; its bulk delete placed no ownership constraint at all. A contributor of
 * organisation A could trash, permanently delete, rename, copy or publish a file of
 * organisation B by posting its identifier. Eight branches affected, no policy, `authorize()`
 * hardcoded to `true`.
 *
 * ⚠️ HERE THE RULE IS NOT IN THE ACTIONS: it is in the step from keys to objects. Destructive
 * actions accept only already-resolved models — that is what makes an action written a year from
 * now scoped without anyone thinking about it.
 *
 * ⚠️ AND A BATCH IS REFUSED WHOLE. Authorising nine items out of ten and executing anyway is
 * exactly what the original did.
 */
class ScopedOperationsTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $current = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');

        self::$current = 'org:a';

        $this->app->singleton(MediaScope::class, fn () => new class implements MediaScope
        {
            public function currentKey(): ?string
            {
                return ScopedOperationsTest::key();
            }

            public function constrain(Builder $query): Builder
            {
                return $query->where('scope_key', ScopedOperationsTest::key());
            }
        });
    }

    public static function key(): ?string
    {
        return self::$current;
    }

    private function within(string $scope, callable $work): mixed
    {
        $before = self::$current;
        self::$current = $scope;

        try {
            return $work();
        } finally {
            self::$current = $before;
        }
    }

    private function media(string $path = 'a.pdf'): Media
    {
        $media = Media::create([
            'disk' => 'media',
            'path' => $path,
            'name' => 'Report',
            'file_name' => basename($path),
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 1024,
        ]);

        Storage::disk('media')->put($path, 'some bytes');

        return $media;
    }

    private function resolver(): SelectionResolver
    {
        return $this->app->make(SelectionResolver::class);
    }

    // ── What a scope cannot see ──────────────────────────────────────────────

    public function test_a_foreign_key_does_not_resolve(): void
    {
        $foreign = $this->within('org:b', fn () => $this->media());

        $this->expectException(ItemNotFound::class);

        $this->resolver()->resolve(new ItemSelection(media: [(string) $foreign->uuid]));
    }

    public function test_a_foreign_folder_does_not_resolve_either(): void
    {
        $foreign = $this->within('org:b', fn () => $this->app->make(CreateFolder::class)('Private'));

        $this->expectException(ItemNotFound::class);

        $this->resolver()->resolve(new ItemSelection(folders: [(string) $foreign->uuid]));
    }

    public function test_a_foreign_key_in_the_trash_does_not_resolve_either(): void
    {
        /*
         * ⚠️ THE TRASH IS THE CLASSIC BLIND SPOT. Restoring and purging work `withTrashed()`,
         * which removes the SOFT-DELETE scope — not the scope of the tenant. Confusing them
         * would reopen the leak on the two most destructive actions.
         */
        $foreign = $this->within('org:b', function () {
            $media = $this->media();
            $media->delete();

            return $media;
        });

        $this->expectException(ItemNotFound::class);

        $this->resolver()->resolve(new ItemSelection(media: [(string) $foreign->uuid]), withTrashed: true);
    }

    public function test_what_belongs_to_the_scope_does_resolve(): void
    {
        /*
         * ⚠️ THE COUNTER-EXAMPLE IS MANDATORY. Without it, a resolver refusing EVERYTHING would
         * pass all the tests above — and this file would certify scoping while proving only a
         * failure.
         */
        $mine = $this->media();

        $resolved = $this->resolver()->resolve(new ItemSelection(media: [(string) $mine->uuid]));

        $this->assertSame(1, $resolved->count());
        $this->assertTrue($resolved->media->first()->is($mine));
    }

    // ── The whole batch, or nothing ──────────────────────────────────────────

    public function test_a_batch_mixing_mine_and_a_foreign_one_does_not_execute_at_all(): void
    {
        $mine = $this->media('mine.pdf');
        $foreign = $this->within('org:b', fn () => $this->media('foreign.pdf'));

        try {
            $resolved = $this->resolver()->resolve(new ItemSelection(media: [
                (string) $mine->uuid,
                (string) $foreign->uuid,
            ]));

            $this->app->make(TrashItems::class)($resolved);
            $this->fail('the batch should have been refused');
        } catch (ItemNotFound) {
            // expected
        }

        /* ⚠️ NEITHER ONE: the refusal happens BEFORE the slightest write. */
        $this->assertNotNull(Media::query()->find($mine->getKey()));
        $this->assertNotNull($this->within('org:b', fn () => Media::query()->find($foreign->getKey())));
    }

    // ── The destructive actions, one by one ──────────────────────────────────

    public function test_the_trash_does_not_touch_another_customers_file(): void
    {
        $foreign = $this->within('org:b', fn () => $this->media());

        $this->expectException(ItemNotFound::class);

        $this->app->make(TrashItems::class)(
            $this->resolver()->resolve(new ItemSelection(media: [(string) $foreign->uuid]))
        );
    }

    public function test_permanent_deletion_does_not_touch_another_customers_file(): void
    {
        $foreign = $this->within('org:b', fn () => $this->media());

        try {
            $this->app->make(ForceDeleteItems::class)(
                $this->resolver()->resolve(new ItemSelection(media: [(string) $foreign->uuid]), withTrashed: true)
            );
            $this->fail('the deletion should have been refused');
        } catch (ItemNotFound) {
            // expected
        }

        $this->assertNotNull($this->within('org:b', fn () => Media::query()->find($foreign->getKey())));
        Storage::disk('media')->assertExists('a.pdf');
    }

    public function test_emptying_your_trash_does_not_empty_the_neighbours(): void
    {
        $foreign = $this->within('org:b', function () {
            $media = $this->media('neighbour.pdf');
            $media->delete();

            return $media;
        });

        $this->app->make(EmptyTrash::class)();

        $this->assertNotNull(
            $this->within('org:b', fn () => Media::withTrashed()->find($foreign->getKey()))
        );
        Storage::disk('media')->assertExists('neighbour.pdf');
    }

    public function test_the_sweep_stays_inside_the_scope_unless_told_otherwise(): void
    {
        $foreign = $this->within('org:b', function () {
            $media = $this->media('neighbour.pdf');
            $media->delete();
            Media::withTrashed()->whereKey($media->getKey())
                ->update(['deleted_at' => now()->subDays(90)]);

            return $media;
        });

        $this->app->make(PruneTrash::class)(30);

        $this->assertNotNull(
            $this->within('org:b', fn () => Media::withTrashed()->find($foreign->getKey()))
        );

        /* ⚠️ THE WAY OUT EXISTS, BUT IT HAS TO BE NAMED. */
        $this->app->make(PruneTrash::class)(30, everyScope: true);

        $this->assertNull(
            $this->within('org:b', fn () => Media::withTrashed()->find($foreign->getKey()))
        );
    }

    public function test_a_foreign_folder_does_not_carry_away_the_neighbours_content(): void
    {
        [$folder, $inside] = $this->within('org:b', function () {
            $folder = $this->app->make(CreateFolder::class)('Private');
            $media = $this->media('inside.pdf');
            $media->folder_id = $folder->getKey();
            $media->save();

            return [$folder, $media];
        });

        try {
            $this->app->make(ForceDeleteItems::class)(
                $this->resolver()->resolve(new ItemSelection(folders: [(string) $folder->uuid]), withTrashed: true)
            );
            $this->fail('the deletion should have been refused');
        } catch (ItemNotFound) {
            // expected
        }

        $this->assertNotNull($this->within('org:b', fn () => Media::query()->find($inside->getKey())));
        $this->assertNotNull($this->within('org:b', fn () => MediaFolder::query()->find($folder->getKey())));
    }

    public function test_an_action_built_by_hand_stays_scoped_by_the_model(): void
    {
        /*
         * ⚠️ THE LAST LINE OF DEFENCE. If somebody bypasses the resolver and builds a selection
         * themselves with a foreign model — because they loaded it without the scope, or because
         * they received it from elsewhere — the actions' queries stay bounded by the global
         * scope: nothing happens.
         */
        $foreign = $this->within('org:b', fn () => $this->media());

        $sneaky = new ResolvedItems(new Collection([$foreign]), new Collection());

        $this->app->make(TrashItems::class)($sneaky);

        $this->assertNotNull($this->within('org:b', fn () => Media::query()->find($foreign->getKey())));
    }
}
