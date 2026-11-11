<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/**
 * The requests the configuration names as the API's: those under one of `problems.route_prefixes`, and every request to
 * one of `problems.hosts`, for an API that answers on a host of its own without a path prefix.
 *
 * Both are read when a request is asked about, so a test or a host that changes them at runtime gets what it set.
 */
final readonly class ConfiguredApiRequests implements RecognizesApiRequests
{
    public function __construct(private Repository $config) {}

    public function isApiRequest(Request $request): bool
    {
        if (in_array(strtolower($request->getHost()), $this->hosts(), true)) {
            return true;
        }

        return array_any($this->routePrefixes(), fn (string $prefix): bool => $request->is($prefix, $prefix.'/*'));
    }

    /**
     * The hosts, trimmed and in lower case, as a request names its own. A setting that is no list names none.
     *
     * @return list<string>
     */
    private function hosts(): array
    {
        $hosts = $this->config->get('http-api.problems.hosts', []);

        if (! is_array($hosts)) {
            return [];
        }

        $names = [];

        foreach ($hosts as $host) {
            if (is_string($host) && trim($host) !== '') {
                $names[] = strtolower(trim($host));
            }
        }

        return $names;
    }

    /**
     * The route prefixes, without their slashes. A setting that is no list names none.
     *
     * @return list<string>
     */
    private function routePrefixes(): array
    {
        $prefixes = $this->config->get('http-api.problems.route_prefixes', ['api']);

        if (! is_array($prefixes)) {
            return [];
        }

        $trimmed = [];

        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && trim($prefix, '/ ') !== '') {
                $trimmed[] = trim($prefix, '/ ');
            }
        }

        return $trimmed;
    }
}
