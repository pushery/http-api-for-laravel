<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

/**
 * An exception that knows the problem document it is answered with.
 *
 * The renderer asks it before any other mapping, so an application's own refusal reaches a client with its own type,
 * title, detail and members instead of a bare `500`.
 */
interface ProvidesProblem
{
    public function toProblem(): Problem;
}
