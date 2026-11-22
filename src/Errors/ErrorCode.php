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
 */
final readonly class ErrorCode
{
    /**
     * @param  int  $status  the HTTP status of the code
     * @param  string  $code  the code itself, `not_found` for instance
     * @param  string  $title  the same for every error of the code
     * @param  array<string, string>  $headers  the headers the code adds to a response, values with placeholders
     * @param  array<string, string>  $details  the details by name, `default` always among them
     * @param  list<string>  $answeredBy  who answers the code: `gateway`, `laravel` or both
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $title,
        public array $headers,
        public array $details,
        public array $answeredBy,
    ) {}

    /**
     * The detail of this name with its placeholders filled.
     *
     * @param  array<string, string>  $values
     *
     * @throws InvalidArgumentException for a detail the code does not have, or a placeholder without a value
     */
    public function detail(string $name, array $values = []): string
    {
        if (! array_key_exists($name, $this->details)) {
            throw new InvalidArgumentException(sprintf('The error code [%s] has no detail named [%s].', $this->code, $name));
        }

        return $this->fill($this->details[$name], $values);
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
     * @param  array<string, string>  $values
     *
     * @throws InvalidArgumentException for a placeholder without a value
     */
    private function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match): string => $values[$match[1]] ?? throw new InvalidArgumentException(
            sprintf('The error code [%s] needs a value for {%s}.', $this->code, $match[1]),
        ), $text);
    }
}
