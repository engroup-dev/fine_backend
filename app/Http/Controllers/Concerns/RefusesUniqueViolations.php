<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\CodedValidation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * W6-06: validation checks a unique rule before the write, without a lock, so
 * two concurrent requests can both pass it; the unique index then stops the
 * second insert or update. This turns that index violation into the same
 * coded 422 the validation path gives, instead of a 500.
 *
 * Only a violation of one of the named indexes is translated; any other
 * unique violation is rethrown untouched. A violation is matched by the
 * index name (PostgreSQL reports it) or by "table.column" (SQLite reports
 * the columns).
 */
trait RefusesUniqueViolations
{
    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @param  list<string>  $markers  index names and "table.column" strings that identify the index
     * @return T
     *
     * @throws ValidationException when one of the named indexes refuses the write.
     */
    protected function refuseUniqueViolation(callable $write, array $markers, string $field, string $message, string $code): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            foreach ($markers as $marker) {
                if (str_contains($e->getMessage(), $marker)) {
                    throw CodedValidation::exception($code, [$field => $message]);
                }
            }

            throw $e;
        }
    }
}
