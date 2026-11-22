<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Preconditions;

/**
 * A model that names its own version, so its entity tag follows that version rather than every column of its row.
 *
 * A version column that every change raises is the usual source: the tag then changes exactly when the version does,
 * and a column no change is counted by, a counter of reads for instance, leaves it alone. The version is signed into
 * the tag together with the table and the key, so a plain number will do.
 */
interface HasEntityVersion
{
    /** The version of the model as it stands, the same for every representation of it. */
    public function entityVersion(): string;
}
