<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * W6-06: a validation refusal the desktop must tell apart from other errors
 * on the same field (a duplicate name versus a too-long one) carries a stable
 * `code` next to the usual `errors`, so the client can map it to Arabic text
 * without matching on the English message.
 *
 * The 422 body is Laravel's validation shape plus `code`:
 * {"message": "...", "code": "WAREHOUSE_NAME_TAKEN", "errors": {"name": ["..."]}}.
 * It is still a ValidationException, so forms that read `errors` keep working.
 */
final class CodedValidation
{
    /**
     * Validates the request like $request->validate(), but a failure of one of
     * the mapped rules carries that rule's code.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $codes  "field.Rule" (the rule in StudlyCase, e.g. "account_id.Unique") => code
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(Request $request, array $rules, array $messages, array $codes): array
    {
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            self::throwIfCoded($validator, $codes);

            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Throws the coded refusal when a mapped rule is among the validator's
     * failures (the first mapping that matches wins); otherwise returns, and
     * the caller throws its usual ValidationException.
     *
     * @param  array<string, string>  $codes  "field.Rule" => code
     *
     * @throws ValidationException
     */
    public static function throwIfCoded(ValidatorContract $validator, array $codes): void
    {
        $failed = $validator->failed();

        foreach ($codes as $fieldRule => $code) {
            [$field, $rule] = explode('.', $fieldRule, 2);

            if (isset($failed[$field][$rule])) {
                $message = (string) ($validator->errors()->first($field) ?: $validator->errors()->first());

                throw new ValidationException($validator, response()->json([
                    'message' => $message,
                    'code' => $code,
                    'errors' => $validator->errors()->messages(),
                ], 422));
            }
        }
    }

    /**
     * A coded refusal raised outside a validator (a check after validation,
     * or a unique index that stopped a concurrent write).
     *
     * @param  array<string, string>  $errors  field => message
     */
    public static function exception(string $code, array $errors): ValidationException
    {
        $exception = ValidationException::withMessages($errors);
        $exception->response = response()->json([
            'message' => (string) reset($errors),
            'code' => $code,
            'errors' => array_map(fn (string $message): array => [$message], $errors),
        ], 422);

        return $exception;
    }
}
