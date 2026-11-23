<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use InvalidArgumentException;

/**
 * One code of the error catalog: its status, its title, the headers it adds to a response and its details.
 *
 * A detail and a header value can hold placeholders such as `{retry_after}`, which the values an error carries fill.
 * A detail is chosen by name, `default` unless the error says otherwise, and every placeholder in it needs a value,
 * so no answer ever shows a placeholder to a client.
 *
 * An application adds details of its own under a code, for the causes that belong to its product alone; see
 * {@see self::declaring()}. The code, its title and its headers stay the catalog's.
 */
final readonly class ErrorCode
{
    /** A placeholder in a detail or a header value, `{retry_after}` for instance. */
    private const string PLACEHOLDER = '/\{([a-z_]+)\}/';

    /** The name of a detail: lower-case letters, digits and underscores, starting with a letter. */
    private const string DETAIL_NAME = '/\A[a-z][a-z0-9_]*\z/';

    /**
     * @param  int  $status  the HTTP status of the code
     * @param  string  $code  the code itself, `not_found` for instance
     * @param  string  $title  the same for every error of the code
     * @param  array<string, string>  $headers  the headers the code adds to a response, values with placeholders
     * @param  array<string, string>  $details  the details by name, `default` always among them
     * @param  list<string>  $answeredBy  who answers the code: `gateway`, `laravel` or both
     * @param  list<string>  $declared  the names of the details the application declared beside the catalog's
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $title,
        public array $headers,
        public array $details,
        public array $answeredBy,
        public array $declared = [],
    ) {}

    /**
     * Whether a text keeps the rules of every text of the catalog: printable ASCII, and none of `<`, `>`, `&`, `/`, `'`,
     * `"` and `\`. With them, the JSON encoders of the gateway and of PHP write the same bytes for the same error.
     */
    public static function keepsTheCatalogRules(string $text): bool
    {
        return preg_match('/\A[\x20-\x7E]*\z/', $text) === 1 && strpbrk($text, '<>&/\'"\\') === false;
    }

    /**
     * The detail of this name with its placeholders filled.
     *
     * The value of a placeholder in a detail the application declared keeps the rules of the catalog's texts as well,
     * since it becomes part of the text.
     *
     * @param  array<string, string>  $values
     *
     * @throws InvalidArgumentException for a detail the code does not have, a placeholder without a value, or a value of
     *                                  a declared detail against the rules of the catalog's texts
     */
    public function detail(string $name, array $values = []): string
    {
        if (! array_key_exists($name, $this->details)) {
            throw new InvalidArgumentException(sprintf('The error code [%s] has no detail named [%s].', $this->code, $name));
        }

        if (in_array($name, $this->declared, true)) {
            foreach ($this->placeholdersIn($this->details[$name]) as $placeholder) {
                if (array_key_exists($placeholder, $values) && ! self::keepsTheCatalogRules($values[$placeholder])) {
                    throw new InvalidArgumentException(sprintf(
                        'The value of {%s} in the detail [%s] of the error code [%s] is not printable ASCII without < > & / \' " and \\.',
                        $placeholder,
                        $name,
                        $this->code,
                    ));
                }
            }
        }

        return $this->fill($this->details[$name], $values);
    }

    /**
     * The code with details of the application's own beside the catalog's, for the causes that belong to its product.
     *
     * A name is lower-case letters, digits and underscores, and one the catalog has under the code already is refused
     * rather than replaced: a client reads a detail of the catalog the same from every API. A text keeps the rules of
     * the catalog's texts, ends with a period, and names a placeholder as `{name}` in lower-case letters and
     * underscores, which declares it.
     *
     * @param  array<string, string>  $details  by name
     *
     * @throws InvalidArgumentException for a name the code has already or that breaks its rule, and for a text that breaks
     *                                  the rules
     */
    public function declaring(array $details): self
    {
        foreach ($details as $name => $text) {
            if (preg_match(self::DETAIL_NAME, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'The detail [%s] declared under the error code [%s] is no name of lower-case letters, digits and underscores.',
                    $name,
                    $this->code,
                ));
            }

            if (array_key_exists($name, $this->details)) {
                throw new InvalidArgumentException(sprintf(
                    'The error code [%s] has a detail named [%s] in the catalog already, so the application declares its own under another name.',
                    $this->code,
                    $name,
                ));
            }

            if (! self::keepsTheCatalogRules($text) || ! str_ends_with($text, '.') || strpbrk((string) preg_replace(self::PLACEHOLDER, '', $text), '{}') !== false) {
                throw new InvalidArgumentException(sprintf(
                    'The detail [%s] declared under the error code [%s] is not printable ASCII without < > & / \' " and \\, ending with a period, with each placeholder written as {name}.',
                    $name,
                    $this->code,
                ));
            }
        }

        return new self(
            $this->status,
            $this->code,
            $this->title,
            $this->headers,
            [...$this->details, ...$details],
            $this->answeredBy,
            [...$this->declared, ...array_keys($details)],
        );
    }

    /**
     * The headers the code adds to a response, their placeholders filled.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     *
     * @throws InvalidArgumentException for a placeholder without a value
     */
    public function headers(array $values = []): array
    {
        return array_map(fn (string $value): string => $this->fill($value, $values), $this->headers);
    }

    /** Whether Laravel answers this code, rather than only the gateway in front of it. */
    public function isAnsweredByLaravel(): bool
    {
        return in_array('laravel', $this->answeredBy, true);
    }

    /**
     * The placeholders a text names, in the order it names them.
     *
     * @return list<string>
     */
    private function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return $matches[1];
    }

    /**
     * @param  array<string, string>  $values
     *
     * @throws InvalidArgumentException for a placeholder without a value
     */
    private function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => $values[$match[1]] ?? throw new InvalidArgumentException(
            sprintf('The error code [%s] needs a value for {%s}.', $this->code, $match[1]),
        ), $text);
    }
}
