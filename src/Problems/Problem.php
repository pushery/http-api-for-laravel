<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * What went wrong with a request, as a problem document (RFC 9457), answered as `application/problem+json`.
 *
 * `type` identifies the kind of problem and `title` names it, the same for every occurrence of that kind. `detail`
 * says what happened this time and `instance` which occurrence it was. A problem without a type of its own is
 * `about:blank`, and its title is then the phrase of its status code, as RFC 9457 §4.2.1 recommends.
 *
 * Members beside these five are extension members (§3.2), such as the fields a validation refused. They are written
 * after the standard members and never in their place, so an extension member named `status` cannot change the status
 * a client reads.
 */
final readonly class Problem
{
    public const string MEDIA_TYPE = 'application/problem+json';

    public const string BLANK = 'about:blank';

    /** The members RFC 9457 defines, in the order the document writes them. */
    private const array STANDARD = ['type', 'title', 'status', 'detail', 'instance'];

    /**
     * @param  int  $status  the HTTP status of the answer, from 400 to 599
     * @param  array<string, mixed>  $members  extension members
     * @param  array<string, string>  $headers  headers of the answer, such as `Retry-After`
     *
     * @throws InvalidArgumentException for a status that is no error
     */
    public function __construct(
        public int $status,
        public string $type = self::BLANK,
        public ?string $title = null,
        public ?string $detail = null,
        public ?string $instance = null,
        public array $members = [],
        public array $headers = [],
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException("A problem document answers an error, and {$status} is no error status.");
        }
    }

    /**
     * The document, standard members first and the ones without a value left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $title = $this->title ?? ($this->type === self::BLANK ? (Response::$statusTexts[$this->status] ?? null) : null);

        $document = array_filter(
            ['type' => $this->type, 'title' => $title, 'status' => $this->status, 'detail' => $this->detail, 'instance' => $this->instance],
            static fn (mixed $value): bool => $value !== null,
        );

        foreach ($this->members as $name => $value) {
            if (! in_array($name, self::STANDARD, true)) {
                $document[$name] = $value;
            }
        }

        return $document;
    }

    public function toResponse(): JsonResponse
    {
        // Written for people as much as for clients: a type URI with its slashes and a title in its own letters.
        return new JsonResponse($this->toArray(), $this->status, [...$this->headers, 'Content-Type' => self::MEDIA_TYPE], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
