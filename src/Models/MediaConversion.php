<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kryption\MediaHub\Backends\HostSchema;
use Kryption\MediaHub\Enums\ConversionState;

/**
 * A DERIVATIVE — a thumbnail, a preview, a poster image.
 *
 * ⚠️ IT HAS ITS OWN STATE, AND THAT IS WHAT LETS A SCREEN SHOW A PLACEHOLDER rather than a
 * broken image while it is being built. A missing derivative never prevents the original from
 * being served.
 *
 * ⚠️ AND IT IS NOT SCOPED ITSELF: it follows its media, which is. A derivative without its
 * original means nothing, and scoping it twice would make two places to maintain.
 */
class MediaConversion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => ConversionState::class,
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function getTable(): string
    {
        return HostSchema::table('conversions');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function isReady(): bool
    {
        return $this->state === ConversionState::Ready;
    }
}
