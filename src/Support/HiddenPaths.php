<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE PATHS THE LIBRARY NEVER SHOWS.
 *
 * ⚠️ AN APPLICATION STORES MORE THAN ITS LIBRARY, and the two live in the same table. Avatars,
 * the attachments of a private conversation, an image posted in a comment: every one of them is
 * a media row, none of them is something to offer in a file browser. Without a way to say so the
 * default is to show everything, and that default is silent — measured on one estate before this
 * existed: 87 attachments from private conversations, 64 avatars and 13 comment images, listed
 * to every back-office user of the organisation and downloadable by identifier.
 *
 * ⚠️ THE PATTERNS COME FROM THE HOST, AND THE PACKAGE SHIPS NONE. Which of a path's segments
 * means "private" is knowledge the host has and the package cannot infer; guessing would either
 * hide somebody's library or leak somebody's conversation.
 */
final class HiddenPaths
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<int, string>
     */
    public function patterns(): array
    {
        $configured = $this->config->get('mediahub.library.hidden', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($pattern): string => trim((string) $pattern), $configured),
            static fn (string $pattern): bool => $pattern !== '',
        ));
    }

    /**
     * ⚠️ ONE GROUP AROUND THE WHOLE THING, and it is not cosmetic. Written flat, these conditions
     * join whatever the caller had already put in the query: a single `orWhere` anywhere else —
     * a search across name and path, say — would swallow them and the concealment would be gone
     * for exactly the request most likely to surface a private file.
     */
    public function apply(Builder $query, string $column): Builder
    {
        $patterns = $this->patterns();

        if ($patterns === []) {
            return $query;
        }

        return $query->where(function (Builder $hidden) use ($patterns, $column): void {
            foreach ($patterns as $pattern) {
                $hidden->where($column, 'not like', self::asComparison($pattern));
            }
        });
    }

    /**
     * A GLOB AS THE COMPARISON UNDERNEATH SPELLS IT.
     *
     * ⚠️ `*` IS THE ONLY THING TRANSLATED, and `_` is left alone deliberately. It means "one
     * character of anything" to the comparison, so a pattern written with an underscore covers a
     * little more than it names. Escaping it would need an `ESCAPE` clause, which is spelled
     * differently by every engine this package runs on — and the cost of not escaping falls the
     * right way: a rule of this kind hides more, never less.
     */
    public static function asComparison(string $pattern): string
    {
        return str_replace('*', '%', $pattern);
    }
}
