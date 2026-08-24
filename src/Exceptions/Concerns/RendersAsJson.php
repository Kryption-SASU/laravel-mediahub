<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Exceptions\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turns a refusal into an HTTP response.
 *
 * ⚠️ WITHOUT THIS, EVERY REFUSAL LEAVES AS A 500. A file that is too large then produces a
 * server error — indistinguishable from an outage, and impossible to explain to whoever
 * uploaded it.
 *
 * ⚠️ THE BODY CARRIES A KEY, AND THE KEY IS THE CONTRACT. `reason` is stable, machine-readable
 * and never translated; a client can branch on it, count it, or render its own wording. The
 * `message` beside it is a courtesy — a default sentence in the current locale — and a client
 * that has its own wording is free to ignore it entirely.
 *
 * ⚠️ AND THE FALLBACK IS THE TECHNICAL MESSAGE, NOT A BLANK. Failures aimed at whoever installs
 * the package — a missing column, an unknown driver — are deliberately untranslated: turning
 * them into a polite sentence would hide the only detail that helps, and make the error
 * unsearchable.
 *
 * ⚠️ THE HOST STILL HAS THE LAST WORD. The framework consults renderers registered by the
 * application BEFORE the one carried by an exception, so a product that wants its own error
 * pages or its own status codes has nothing to work around.
 */
trait RendersAsJson
{
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->translated(),
            'reason' => $this->reasonKey(),
        ], $this->status());
    }

    abstract protected function status(): int;

    protected function reasonKey(): string
    {
        return property_exists($this, 'reason') ? (string) $this->reason : $this->getMessage();
    }

    /**
     * ⚠️ A KEY WITH NO LINE FALLS BACK TO THE TECHNICAL MESSAGE, never to the raw key. Laravel
     * returns the lookup string itself when a translation is missing, so a naive call would
     * put `mediahub::errors.storage_path_traversal: /var/…` in front of a user. Comparing the
     * result against the lookup is what distinguishes "translated" from "not found".
     */
    private function translated(): string
    {
        $key = 'mediahub::errors.'.$this->reasonKey();

        $line = trans($key);

        return is_string($line) && $line !== $key ? $line : $this->getMessage();
    }
}
