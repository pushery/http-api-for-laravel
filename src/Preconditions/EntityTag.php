<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Preconditions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The entity tag of a model, as the `ETag` header carries it: the version of the resource a response shows and a change
 * is held against.
 *
 * The tag is taken from the model's row as the database holds it, read again by the model's key, so an answer, a later
 * read and the check of a change all compare the same thing, whatever the model held in memory. A model that
 * implements {@see HasEntityVersion} is tagged by the version it names. Every other model is tagged by every column of
 * its row: any change that reaches the database changes the tag, two within the same second included, while the
 * representation a request asks for through `include` or `fields` changes nothing, so the tag of one representation
 * holds for every other. The row is signed with the application key rather than hashed, so a tag gives nothing away
 * about the columns it covers, a hidden one included.
 */
final readonly class EntityTag
{
    public function __construct(private Repository $config) {}

    /**
     * The tag of the model as the database holds it now.
     *
     * @param  bool  $lock  whether the row stays locked against every other change until the transaction ends
     * @return string|null the tag in quotes, or null for a model that was never saved or whose row is gone
     *
     * @throws RuntimeException where the application has no key to sign with
     */
    public function of(Model $model, bool $lock = false): ?string
    {
        if (! $model->exists) {
            return null;
        }

        $query = $model->newQueryWithoutScopes()->whereKey($model->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($model instanceof HasEntityVersion) {
            $fresh = $query->first();

            return $fresh instanceof HasEntityVersion ? $this->signed($model, [$this->keyOf($model), $fresh->entityVersion()]) : null;
        }

        $row = $query->toBase()->first();

        return $row === null ? null : $this->signed($model, $this->columns((array) $row));
    }

    /**
     * The columns of a row in the order of their names, a binary column that the driver hands over as a stream read
     * into its bytes, as PostgreSQL's does.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private function columns(array $row): array
    {
        ksort($row);

        foreach ($row as $column => $value) {
            if (is_resource($value)) {
                $row[$column] = stream_get_contents($value);
            }
        }

        return $row;
    }

    private function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * The version signed with the application key under the model's table, in quotes as a strong entity tag.
     *
     * @param  array<array-key, mixed>  $version
     *
     * @throws RuntimeException where the application has no key to sign with
     */
    private function signed(Model $model, array $version): string
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Entity tags are signed with the application key, and app.key is empty.');
        }

        return '"'.substr(hash_hmac('sha256', serialize([$model->getTable(), $version]), $key), 0, 32).'"';
    }
}
