<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Preconditions;

use Pushery\HttpApi\Errors\ErrorDocument;
use Pushery\HttpApi\Errors\JsonApiError;
use Pushery\HttpApi\Errors\ProvidesErrorDocument;
use RuntimeException;

/**
 * Why a change of a resource was not let through, with the statuses of RFC 9110 and RFC 6585: `428`
 * `precondition_required` for a change without an `If-Match` header, and `412` `precondition_failed` for one whose
 * `If-Match` names a version the resource no longer has. Both name the header in `source.header`, and in neither case
 * has the route run.
 *
 * `reason` names the case for a log or a test.
 */
final class PreconditionRefusal extends RuntimeException implements ProvidesErrorDocument
{
    public const string HEADER = 'If-Match';

    /**
     * @param  string  $reason  the case, `if-match-missing` for instance
     * @param  string  $errorCode  the code of the catalog the case is answered with
     */
    private function __construct(public readonly string $reason, private readonly string $errorCode)
    {
        parent::__construct($reason);
    }

    /** The change came without an `If-Match` header, so nothing says which version it changes. */
    public static function required(): self
    {
        return new self('if-match-missing', 'precondition_required');
    }

    /** The resource has changed since the version the `If-Match` header names, or it is gone. */
    public static function failed(): self
    {
        return new self('if-match-outdated', 'precondition_failed');
    }

    public function toErrorDocument(): ErrorDocument
    {
        return ErrorDocument::of(JsonApiError::of($this->errorCode, source: ['header' => self::HEADER]));
    }
}
