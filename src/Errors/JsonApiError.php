<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use InvalidArgumentException;

/**
 * One error object of JSON:API 1.1, made from a code of the error catalog.
 *
 * Its title and its detail come from the catalog, never from the message of an exception: such a message is written
 * for a developer, can name classes and keys, and would make an error from Laravel differ from the same error from the
 * gateway. `source` names the input that caused the error, a member of the body by its JSON pointer, a query parameter
 * or a header. The placeholders of the detail and of the code's headers are filled when the error is made, so an error
 * that lacks a value fails where it is made and never while a response is written.
 */
final readonly class JsonApiError
{
    /**
     * @param  array<string, string>  $source
     * @param  array<string, string>  $headers  the headers the code adds to a response, filled
     */
    private function __construct(
        public ErrorCode $code,
        public string $detail,
        public array $source,
        public array $headers,
    ) {}

    /**
     * An error of a code of the catalog, with one of the code's details: the catalog's own, or one the application
     * declares under the code in `http-api.errors.details`.
     *
     * @param  string  $code  a code of the catalog, `conflict` for instance
     * @param  string  $detail  the name of one of the code's details
     * @param  array<string, string>  $values  the values of the placeholders in the detail and in the code's headers
     * @param  array{pointer?: string, parameter?: string, header?: string}  $source  the input that caused the error
     *
     * @throws InvalidArgumentException for a code or a detail the catalog does not have, a placeholder without a value,
     *                                  or a value of a declared detail against the rules of the catalog's texts
     */
    public static function of(string $code, string $detail = 'default', array $values = [], array $source = []): self
    {
        return self::from(ErrorCodes::current()->code($code), $detail, $values, $source);
    }

    /**
     * The error Laravel answers a status with, `internal_error` for a status the catalog has no code of Laravel's for.
     *
     * @param  array<string, string>  $values
     * @param  array{pointer?: string, parameter?: string, header?: string}  $source
     *
     * @throws InvalidArgumentException for a placeholder without a value
     */
    public static function forStatus(int $status, array $values = [], array $source = []): self
    {
        return self::from(ErrorCodes::shipped()->forStatus($status), 'default', $values, $source);
    }

    /**
     * The error object, its members in the order of the catalog, `source` left out where no input caused the error.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $requestId): array
    {
        $members = [
            'status' => (string) $this->code->status,
            'code' => $this->code->code,
            'title' => $this->code->title,
            'detail' => $this->detail,
            'source' => $this->source,
            'meta' => ['request_id' => $requestId],
        ];

        if ($this->source === []) {
            unset($members['source']);
        }

        return $members;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array{pointer?: string, parameter?: string, header?: string}  $source
     */
    private static function from(ErrorCode $code, string $detail, array $values, array $source): self
    {
        return new self($code, $code->detail($detail, $values), $source, $code->headers($values));
    }
}
