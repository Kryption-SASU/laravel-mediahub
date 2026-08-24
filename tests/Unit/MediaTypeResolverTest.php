<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kryption\MediaHub\Enums\MediaType;
use Kryption\MediaHub\Support\MimeMediaTypeResolver;

/**
 * A MEDIA'S NATURE IS READ FROM ITS MIME TYPE.
 *
 * ⚠️ WE LOOK AT THE FAMILY BEFORE THE EXACT TYPE: an exhaustive list is a list that ages, and an
 * image format invented tomorrow must be recognised as an image.
 */
class MediaTypeResolverTest extends TestCase
{
    private MimeMediaTypeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MimeMediaTypeResolver();
    }

    public function test_families_are_recognised_by_their_prefix(): void
    {
        $this->assertSame(MediaType::Image, $this->resolver->resolve('image/png'));
        $this->assertSame(MediaType::Video, $this->resolver->resolve('video/mp4'));
        $this->assertSame(MediaType::Audio, $this->resolver->resolve('audio/mpeg'));
    }

    /** ⚠️ A FORMAT THAT DID NOT EXIST YESTERDAY is still an image. */
    public function test_an_unknown_format_of_the_image_family_is_still_an_image(): void
    {
        $this->assertSame(MediaType::Image, $this->resolver->resolve('image/jxl'));
    }

    public function test_office_documents_are_documents(): void
    {
        $this->assertSame(MediaType::Document, $this->resolver->resolve('application/pdf'));
        $this->assertSame(
            MediaType::Document,
            $this->resolver->resolve('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        );
    }

    /** ⚠️ AN UNKNOWN TYPE DOES NOT RAISE: refusing to classify is refusing to serve. */
    public function test_an_unknown_type_does_not_raise(): void
    {
        $this->assertSame(MediaType::Other, $this->resolver->resolve('application/x-unknown'));
    }

    public function test_case_and_spacing_change_nothing(): void
    {
        $this->assertSame(MediaType::Image, $this->resolver->resolve('  IMAGE/PNG '));
    }
}
