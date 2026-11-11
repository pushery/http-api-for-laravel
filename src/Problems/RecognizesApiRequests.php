<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

use Illuminate\Http\Request;

/**
 * Which requests belong to the API, and so have their failures answered with a problem document.
 *
 * The package binds {@see ConfiguredApiRequests}, which reads the route prefixes and the hosts of the configuration.
 * An application whose routes know their API better, by a route group, a middleware or the mode its API runs in, binds
 * its own, and the package asks that one instead. A request that asks for `application/problem+json`, and with
 * `problems.when_json_is_expected` one that expects JSON, gets a problem document whatever this answers.
 */
interface RecognizesApiRequests
{
    public function isApiRequest(Request $request): bool;
}
