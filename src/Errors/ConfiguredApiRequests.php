<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/**
 * The requests the configuration names as the API's: every request to the API host, `errors.api_host` or `api.` before
 * the host of `app.url`, every request to one of `errors.hosts`, and those under one of `errors.route_prefixes`.
 *
 * Both are read when a request is asked about, so a test or a host that changes them at runtime gets what it set.
 */
final readonly class ConfiguredApiRequests implements RecognizesApiRequests
{
    public function __construct(private Repository $config) {}

    public function isApiRequest(Request $request): bool
    {
        $host = strtolower($request->getHost());

        if ($host === $this->apiHost() || in_array($host, $this->hosts(), true)) {
            return true;
        }

        return array_any($this->routePrefixes(), fn (string $prefix): bool => $request->is($prefix, $prefix.'/*'));
    }

    /**
     * The host of the API in lower case: `errors.api_host`, or where that is unset `api.` before the host of `app.url`
     * without a leading `www.`, and that host itself where it starts with `api.`. Null where neither names a host.
     */
    private function apiHost(): ?string
    {
        $configured = $this->config->get('http-api.errors.api_host');

        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        $url = $this->config->get('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'api.') ? $host : 'api.'.(str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }

    /**
     * The hosts, trimmed and in lower case, as a request names its own. A setting that is no list names none.
     *
     * @return list<string>
     */
    private function hosts(): array
    {
        $hosts = $this->config->get('http-api.errors.hosts', []);

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
        $prefixes = $this->config->get('http-api.errors.route_prefixes', []);

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
