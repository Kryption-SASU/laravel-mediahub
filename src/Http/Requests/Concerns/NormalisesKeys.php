<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Requests\Concerns;

/**
 * AN IDENTIFIER ARRIVES AS A NUMBER AS OFTEN AS AS A STRING, AND BOTH MEAN THE SAME KEY.
 *
 * ⚠️ THE ROUTE KEY IS NOT ALWAYS A STRING, AND THIS PACKAGE SAYS SO ITSELF. `standalone` keys on
 * a `uuid`; the `legacy` preset keys on the host's `id`, an auto-incrementing integer. JSON has
 * no way of telling "the identifier 12" from "the number 12", so a client echoing back what the
 * API gave it sends a number — and a `string` rule refuses it with "media.0 must be a string",
 * a message that names a type rather than a problem.
 *
 * ⚠️ MEASURED, NOT IMAGINED. On 25/08/2026, on a host running the legacy preset, deleting for
 * good, trashing, restoring, downloading a selection and creating a folder inside another all
 * answered 422. The package's own suite was green throughout: it only ever runs `standalone`,
 * where the keys really are strings — so it proved the shape of one driver rather than the
 * contract both are supposed to honour.
 *
 * ⚠️ NORMALISED BEFORE VALIDATION RATHER THAN ALLOWED THROUGH IT. The rule stays `string`, so an
 * array, an object or a null — shapes that are genuinely wrong — still fail, and still fail with
 * a message that says which. Only the scalar that was always a key is put into its declared
 * form.
 */
trait NormalisesKeys
{
    /**
     * @param  list<string>  $fields
     */
    protected function normaliseKeys(array $fields): void
    {
        $changes = [];

        foreach ($fields as $field) {
            /*
             * ⚠️ ONLY WHAT WAS ACTUALLY SENT. Merging an absent field turns "the caller said
             * nothing about this" into "the caller sent an empty value", and `sometimes` reads
             * the two differently: a rename would start moving the file to the root.
             */
            if (! $this->has($field)) {
                continue;
            }

            $changes[$field] = self::asKey($this->input($field));
        }

        if ($changes !== []) {
            $this->merge($changes);
        }
    }

    /**
     * ⚠️ INTEGERS ONLY, AND NOT FLOATS. `12.0` is not an identifier any driver here produces;
     * converting it would turn a malformed payload into a lookup that quietly finds nothing,
     * where refusing it says what went wrong.
     */
    private static function asKey(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::asKey($item), $value);
        }

        return is_int($value) ? (string) $value : $value;
    }
}
