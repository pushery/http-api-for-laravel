<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Pagination;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * A list that pages with a cursor, as JSON:API clients page: `page[size]` items a page, `page[cursor]` for where the
 * page starts, and `links.next` and `links.prev` instead of page numbers.
 *
 * A request without `page[size]` gets `pagination.size` items. A size that is no whole number from one up to
 * `pagination.max_size`, and a cursor that is not one of the list's, are refused with `400` and the parameter in
 * `source.parameter`, never answered with a first page. The model's key is ordered by last, unless the query orders by
 * it already, so rows whose sort columns hold the same values are neither skipped nor repeated between two pages. The
 * links keep every other parameter of the request, the filters, the sorting and `page[size]` among them.
 *
 * The pages come from Laravel's cursor paginator, which every Laravel application has: the component
 * `illuminate/pagination`.
 */
final readonly class CursorPages
{
    /** The parameter that names where a page starts. */
    public const string CURSOR = 'page[cursor]';

    /** The parameter that names how many items a page holds. */
    public const string SIZE = 'page[size]';

    /** The member of an encoded cursor that says which way it points. */
    private const string DIRECTION = '_pointsToNextItems';

    public function __construct(private Repository $config) {}

    /**
     * The page of the query the request asks for, ready for `JsonApiResource::collection()`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  ordered by the list's sorting, if it has one
     * @return CursorPaginator<int, TModel>
     *
     * @throws InvalidPageParameter for a size or a cursor the list cannot take
     */
    public function of(Builder $query, Request $request): CursorPaginator
    {
        $size = $this->size($request);
        $model = $query->getModel();
        $columns = $this->orderColumns($query);

        if (! in_array($model->getQualifiedKeyName(), $columns, true) && ! in_array($model->getKeyName(), $columns, true)) {
            $query->orderBy($model->getQualifiedKeyName());
            $columns[] = $model->getQualifiedKeyName();
        }

        return $query->cursorPaginate($size, ['*'], self::CURSOR, $this->cursor($request, $columns))
            ->appends(Arr::except($request->query->all(), ['page.cursor']));
    }

    /**
     * The size the request asks for, or `pagination.size` where it names none, never more than `pagination.max_size`.
     *
     * @throws InvalidPageParameter for a size that is no whole number from one up to the largest
     */
    private function size(Request $request): int
    {
        $size = $request->input('page.size');
        $largest = $this->positive('max_size', 100);

        if ($size === null) {
            return min($this->positive('size', 20), $largest);
        }

        if (! is_string($size) || preg_match('/^[0-9]+$/D', $size) !== 1 || (int) $size < 1 || (int) $size > $largest) {
            throw InvalidPageParameter::size($largest);
        }

        return (int) $size;
    }

    /**
     * The cursor the request names, checked against the list before Laravel reads it: an encoded object with the
     * direction it points in and a plain value for each column the list is ordered by, and for nothing else.
     *
     * @param  list<string>  $columns  the columns the list is ordered by
     *
     * @throws InvalidPageParameter for a cursor that is not one of the list's
     */
    private function cursor(Request $request, array $columns): ?string
    {
        $encoded = $request->input('page.cursor');

        if ($encoded === null) {
            return null;
        }

        $parameters = is_string($encoded) ? json_decode(base64_decode(strtr($encoded, '-_', '+/')), true) : null;

        if (! is_array($parameters) || ! is_bool($parameters[self::DIRECTION] ?? null)) {
            throw InvalidPageParameter::cursor();
        }

        unset($parameters[self::DIRECTION]);
        $named = array_keys($parameters);
        sort($named);
        sort($columns);

        if ($named !== $columns) {
            throw InvalidPageParameter::cursor();
        }

        foreach ($parameters as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw InvalidPageParameter::cursor();
            }
        }

        return $encoded;
    }

    /**
     * The columns the query is ordered by, as Laravel names them in a cursor.
     *
     * @param  Builder<covariant Model>  $query
     * @return list<string>
     */
    private function orderColumns(Builder $query): array
    {
        $columns = [];

        foreach ($query->getQuery()->orders ?? [] as $order) {
            if (is_array($order) && is_string($order['column'] ?? null)) {
                $columns[] = $order['column'];
            }
        }

        return $columns;
    }

    /** A positive whole number from the pagination settings, or the shipped value where the setting is none. */
    private function positive(string $key, int $shipped): int
    {
        $value = $this->config->get('http-api.pagination.'.$key);

        return is_int($value) && $value >= 1 ? $value : $shipped;
    }
}
