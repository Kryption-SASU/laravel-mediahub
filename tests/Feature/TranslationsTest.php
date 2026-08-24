<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Feature;

use Illuminate\Support\Facades\App;
use Kryption\MediaHub\Exceptions\OperationRejected;
use Kryption\MediaHub\Tests\TestCase;

/**
 * Shipped wording for refusals, and the contract that survives it.
 *
 * ⚠️ THE KEY IS THE CONTRACT, THE SENTENCE IS A COURTESY. A client branches on `reason`, which
 * never changes and is never translated. `message` exists so that a host gets readable
 * refusals from `composer require` alone, without publishing anything.
 */
class TranslationsTest extends TestCase
{
    private function body(string $key, ?string $locale = null): array
    {
        if ($locale !== null) {
            App::setLocale($locale);
        }

        $response = OperationRejected::because($key)->render(request());

        return (array) json_decode((string) $response->getContent(), true);
    }

    public function test_a_refusal_carries_the_key_and_a_sentence(): void
    {
        $body = $this->body('folder_name_required', 'en');

        $this->assertSame('folder_name_required', $body['reason']);
        $this->assertSame('A folder name is required.', $body['message']);
    }

    public function test_the_sentence_follows_the_application_locale(): void
    {
        $this->assertSame('Un nom de dossier est requis.', $this->body('folder_name_required', 'fr')['message']);
    }

    public function test_the_key_never_changes_language(): void
    {
        /*
         * ⚠️ THE WHOLE POINT. If `reason` moved with the locale, a client could not branch on
         * it — and the first host to translate its interface would break every integration
         * silently, because nothing about a changed string raises an error.
         */
        $this->assertSame(
            $this->body('archive_empty', 'en')['reason'],
            $this->body('archive_empty', 'fr')['reason']
        );
    }

    public function test_a_key_without_a_line_falls_back_to_the_technical_message(): void
    {
        /*
         * ⚠️ AND NOT ON THE LOOKUP STRING. Laravel returns the lookup itself when a line is
         * missing, so a naive call would show `mediahub::errors.…` to a user. Failures aimed at
         * whoever installs the package are deliberately untranslated, and this is the path they
         * take.
         */
        $body = $this->body('storage_path_traversal: /var/data/../www', 'fr');

        $this->assertStringNotContainsString('mediahub::', $body['message']);
        $this->assertStringContainsString('/var/data/../www', $body['message']);
    }

    public function test_both_locales_cover_exactly_the_same_keys(): void
    {
        /*
         * ⚠️ A KEY ADDED TO ONE FILE AND NOT THE OTHER DOES NOT RAISE ANYTHING. It falls back
         * to the technical message in the missing language only — so the refusal reads fine in
         * testing and turns technical for half the users, with nothing to signal it.
         */
        $en = array_keys(require __DIR__.'/../../lang/en/errors.php');
        $fr = array_keys(require __DIR__.'/../../lang/fr/errors.php');

        sort($en);
        sort($fr);

        $this->assertSame($en, $fr, 'the two locales do not cover the same keys');
    }
}
