<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
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
     * The catalog the errors of the application are made from: the shipped one, with the details the application
     * declares under its codes in `http-api.errors.details`, read again whenever that setting changes.
     *
     * @throws InvalidArgumentException for a declaration the catalog refuses, see {@see self::withDetails()}
     */
    public static function current(): self
    {
        static $catalog = null;
        static $from = null;

        $declared = self::declaredByTheApplication();

        if ($declared === []) {
            return self::shipped();
        }

        if (! $catalog instanceof self || $from !== $declared) {
            $catalog = self::shipped()->withDetails($declared);
            $from = $declared;
        }

        return $catalog;
    }

    /**
     * The catalog with details an application declares under its codes, by code and then by name, for the causes that
     * belong to its product. The codes stay the catalog's: a client acts on a code, and a person reads the detail.
     *
     * @param  array<mixed>  $declared  by code, then by name, as `http-api.errors.details` holds them
     *
     * @throws InvalidArgumentException for a code the catalog does not have, a name the code has already, and a name
     *                                  or a text that breaks the rules of {@see ErrorCode::declaring()}
     */
    public function withDetails(array $declared): self
    {
        $codes = $this->codes;

        foreach ($declared as $code => $details) {
            if (! is_string($code) || ! is_array($details)) {
                throw new InvalidArgumentException('http-api.errors.details holds the details of the application by the code of the catalog, then by name.');
            }

            $named = [];

            foreach ($details as $name => $text) {
                if (! is_string($name) || ! is_string($text)) {
                    throw new InvalidArgumentException(sprintf('http-api.errors.details holds the details under [%s] by name, each a text.', $code));
                }

                $named[$name] = $text;
            }

            $codes[$code] = $this->code($code)->declaring($named);
        }

        return new self($this->responseHeaders, $this->memberOrder, $codes);
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
     * comes first.
     *
     * A client error the catalog has no code of Laravel's for is answered as `malformed_request`, a `400`, so it stays
     * a refusal a client does not send again unchanged, where a `5xx` would have it retried. Any other status the
     * catalog has none for is answered as `internal_error`, as every failure is that no code describes.
     */
    public function forStatus(int $status): ErrorCode
    {
        foreach ($this->codes as $code) {
            if ($code->status === $status && $code->isAnsweredByLaravel()) {
                return $code;
            }
        }

        return $this->code($status >= 400 && $status < 500 ? 'malformed_request' : 'internal_error');
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

    /**
     * The details the application declares in `http-api.errors.details`, none outside an application.
     *
     * @return array<mixed>
     *
     * @throws InvalidArgumentException for a setting that is no map
     */
    private static function declaredByTheApplication(): array
    {
        $container = Container::getInstance();
        $config = $container->bound('config') ? $container->make('config') : null;
        $declared = $config instanceof Repository ? $config->get('http-api.errors.details', []) : [];

        return is_array($declared)
            ? $declared
            : throw new InvalidArgumentException('http-api.errors.details holds the details of the application by the code of the catalog, then by name.');
    }
}
