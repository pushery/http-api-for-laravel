<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * The error catalog: every code an API answers with, from the file the gateway in front of the application reads too.
 *
 * The package ships the file in GatewayCatalog, a copy of the gateway's byte for byte, so an
 * error the gateway answers and the same error from Laravel look alike: the same status, the same title, the same
 * detail and the same headers. The catalog also names the headers of every error response and the media type.
 */
final readonly class ErrorCodes
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param  array<string, string>  $responseHeaders  the headers of every error response
     * @param  list<string>  $memberOrder  the order the members of an error object are written in
     * @param  array<string, ErrorCode>  $codes  by code
     */
    private function __construct(
        public array $responseHeaders,
        public array $memberOrder,
        private array $codes,
    ) {}

    /** The catalog the package ships, read once per process. */
    public static function shipped(): self
    {
        static $catalog = null;

        if (! $catalog instanceof self) {
            $catalog = self::fromJson(GatewayCatalog::json(), GatewayCatalog::FILE);
        }

        return $catalog;
    }

    /**
     * A catalog read from a file in the format of the gateway's.
     *
     * @throws UnexpectedValueException for a file that cannot be read or is not in that format
     */
    public static function fromFile(string $path): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new UnexpectedValueException(sprintf('The error catalog [%s] cannot be read.', $path));
        }

        return self::fromJson($contents, $path);
    }

    /**
     * A catalog read from JSON in the format of the gateway's file, the source named in a refusal.
     *
     * @throws UnexpectedValueException for JSON that is not in that format
     */
    public static function fromJson(string $contents, string $source): self
    {
        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(sprintf('The error catalog [%s] holds no JSON.', $source), $exception->getCode(), previous: $exception);
        }

        $entries = is_array($data) ? ($data['codes'] ?? null) : null;

        if (! is_array($data) || ($data['media_type'] ?? null) !== self::MEDIA_TYPE || ! is_array($entries)) {
            throw new UnexpectedValueException(sprintf('The error catalog [%s] is not in the format of the gateway.', $source));
        }

        $codes = [];

        foreach ($entries as $entry) {
            $code = self::entry($entry, $source);
            $codes[$code->code] = $code;
        }

        return new self(self::strings($data['response_headers'] ?? null, $source), array_values(self::strings($data['member_order'] ?? null, $source)), $codes);
    }

    /**
     * The code of this name.
     *
     * @throws InvalidArgumentException for a code the catalog does not have
     */
    public function code(string $code): ErrorCode
    {
        return $this->codes[$code] ?? throw new InvalidArgumentException(sprintf('The error catalog has no code [%s].', $code));
    }

    /**
     * The code Laravel answers a status with: the first in the catalog's order that Laravel answers. `409` has two,
     * `conflict` and `request_in_flight`, the refusal of a running request under an idempotency key, and `conflict`
     * comes first. A status the catalog has none for is answered as `internal_error`, as every failure is that no code
     * describes.
     */
    public function forStatus(int $status): ErrorCode
    {
        foreach ($this->codes as $code) {
            if ($code->status === $status && $code->isAnsweredByLaravel()) {
                return $code;
            }
        }

        return $this->code('internal_error');
    }

    /**
     * @param  mixed  $entry  one element of `codes` as the JSON decodes it, which a file can make anything
     *
     * @throws UnexpectedValueException for an entry that is not a complete code
     */
    private static function entry(mixed $entry, string $path): ErrorCode
    {
        $entry = is_array($entry) ? $entry : [];
        $status = $entry['status'] ?? null;
        $code = $entry['code'] ?? null;
        $title = $entry['title'] ?? null;

        if (! is_int($status) || ! is_string($code) || ! is_string($title)) {
            throw new UnexpectedValueException(sprintf('The error catalog [%s] holds a code without a status, a code or a title.', $path));
        }

        $details = self::strings($entry['details'] ?? null, $path);

        if (! array_key_exists('default', $details)) {
            throw new UnexpectedValueException(sprintf('The error code [%s] in [%s] has no default detail.', $code, $path));
        }

        return new ErrorCode(
            $status,
            $code,
            $title,
            self::strings($entry['headers'] ?? null, $path),
            $details,
            array_values(self::strings($entry['answered_by'] ?? null, $path)),
        );
    }

    /**
     * A map or list of strings, keyed as the file keys it.
     *
     * @param  mixed  $value  a member of the file as the JSON decodes it, which a file can make anything
     * @return array<string, string>
     *
     * @throws UnexpectedValueException for anything else
     */
    private static function strings(mixed $value, string $path): array
    {
        if (! is_array($value) || array_filter($value, is_string(...)) !== $value) {
            throw new UnexpectedValueException(sprintf('The error catalog [%s] holds a list or map that is not all strings.', $path));
        }

        $strings = [];

        foreach ($value as $key => $string) {
            $strings[(string) $key] = $string;
        }

        return $strings;
    }
}
