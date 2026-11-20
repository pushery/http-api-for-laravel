<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

use Throwable;

/**
 * The application's names for the problems the package writes itself.
 *
 * The package answers a validation, a missing authentication, an HTTP exception of Laravel or Symfony and an exception
 * nobody anticipated with a document of its own, typed `about:blank`. An application with a catalog of its own error
 * types and codes binds this, and the package hands it each such document together with the failure it answers, so the
 * application can return it with its `type`, its `title` and members such as a stable `code`. What it returns is the
 * answer, its status included. A failure that already answers with its own problem, through {@see ProvidesProblem}, is
 * the application's own and is not handed here. Without a binding, every document stays as the package wrote it.
 */
interface NamesProblems
{
    /** The document to answer with: the one the package wrote for the failure, renamed, or that one unchanged. */
    public function name(Problem $problem, Throwable $failure): Problem;
}
