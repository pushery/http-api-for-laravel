<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Pagination;

use Pushery\HttpApi\Errors\ErrorDocument;
use Pushery\HttpApi\Errors\JsonApiError;
use Pushery\HttpApi\Errors\ProvidesErrorDocument;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A `page` parameter a list cannot take: a size outside its bounds, or a cursor that is not one of the list's.
 *
 * It is answered with `400` `malformed_request`, naming the parameter in `source.parameter`, rather than with a first
 * page nobody asked for. The detail names the case and what the parameter takes: `page_size_out_of_range` with the
 * largest size, and `page_cursor_unknown`. The message says the same for the report.
 */
final class InvalidPageParameter extends HttpException implements ProvidesErrorDocument
{
    /**
     * @param  string  $parameter  the parameter as the query names it, `page[size]` for instance
     * @param  string  $detail  the detail of `malformed_request` that names the case
     * @param  array<string, string>  $values  the values of the detail's placeholders
     */
    private function __construct(
        public readonly string $parameter,
        private readonly string $detail,
        private readonly array $values,
        string $message,
    ) {
        parent::__construct(400, $message);
    }

    /** A size that is no whole number from one up to the largest the list gives. */
    public static function size(int $largest): self
    {
        return new self(
            CursorPages::SIZE,
            'page_size_out_of_range',
            ['max_size' => (string) $largest],
            sprintf('%s takes a whole number from 1 to %d.', CursorPages::SIZE, $largest),
        );
    }

    /** A cursor that is not the one of a `links.next` or `links.prev` of the list. */
    public static function cursor(): self
    {
        return new self(
            CursorPages::CURSOR,
            'page_cursor_unknown',
            [],
            sprintf('%s takes the cursor of a links.next or links.prev of this list.', CursorPages::CURSOR),
        );
    }

    public function toErrorDocument(): ErrorDocument
    {
        return ErrorDocument::of(JsonApiError::of('malformed_request', $this->detail, $this->values, ['parameter' => $this->parameter]));
    }
}
