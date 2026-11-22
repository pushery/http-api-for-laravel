<?php

declare(strict_types=1);

namespace Pushery\HttpApi;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\HttpApi\Errors\ConfiguredApiRequests;
use Pushery\HttpApi\Errors\ErrorRenderer;
use Pushery\HttpApi\Errors\RecognizesApiRequests;
use Pushery\HttpApi\Idempotency\AuthenticatedUserScope;
use Pushery\HttpApi\Idempotency\ResolvesIdempotencyScope;
use Throwable;

final class HttpApiServiceProvider extends ServiceProvider
{
    /**
     * The sections of the config that are catalogs, as dotted paths from the config key: maps whose
     * keys the host chooses, one entry per plan, per document or per signing key. A key that a
     * published catalog leaves out was removed by the host rather than added by a later release, so
     * the merge takes a published catalog as it stands. A catalog the host never published arrives
     * whole from the default. A package lists such a section here, for example
     * 'http-api.plans'.
     *
     * @var list<string>
     */
    private const array CATALOG_SECTIONS = [];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/http-api.php', 'http-api');

        // Bound only where the application has not bound its own, so a host that knows its callers by an API key or a
        // signed service replaces the default in its own provider, whichever of the two registers first.
        $this->app->bindIf(ResolvesIdempotencyScope::class, AuthenticatedUserScope::class);

        // The same for the requests that belong to the API: the configuration's API host, hosts and prefixes, unless
        // the application knows its API better and binds its own.
        $this->app->bindIf(RecognizesApiRequests::class, ConfiguredApiRequests::class);
    }

    public function boot(): void
    {
        $this->registerErrorRenderer();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    /**
     * Hand the error renderer to Laravel's exception handler, which asks it before rendering a failure itself.
     *
     * The switches are read when a failure is rendered, not here, so a test or a host that changes them at runtime gets
     * what it set. They and the renderer come from the container current at that moment, not from the application this
     * provider was built with: under a persistent worker such as Laravel Octane, each request runs on a copy of the
     * application with a configuration of its own, while the handler and this callback, built once, outlive it. A
     * handler without render callbacks, one an application wrote from scratch against the contract, gets nothing, and
     * the application renders the errors itself through {@see ErrorRenderer::render()}.
     *
     * Registered on whichever handler the application resolves, so one it binds after this provider boots is asked
     * too.
     */
    private function registerErrorRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            // A handler of the application's own, without Laravel's render callbacks, calls ErrorRenderer::render() itself.
            if (method_exists($handler, 'renderable')) {
                $handler->renderable(static function (Throwable $failure, Request $request): ?JsonResponse {
                    $container = Container::getInstance();
                    $config = $container->make(Repository::class);

                    if ($config->get('http-api.enabled') === false || $config->get('http-api.errors.enabled') === false) {
                        return null;
                    }

                    return $container->make(ErrorRenderer::class)->render($failure, $request);
                });
            }
        });
    }

    /**
     * Lay the shipped defaults under a published config file, all the way down.
     *
     * Laravel's own `mergeConfigFrom()` is a single `array_merge` at the top level, so it asks
     * one question per top-level key: is it there? A host that published the config file has
     * every top-level key, so a key that a later release adds inside one of those blocks would
     * never arrive, and the block the host published would win whole.
     *
     * Nothing would error or log: the new setting would read as `null` (or as `[]`). A feature
     * added in a minor release would be off for exactly the hosts that customized that area, and
     * a corrected security default would not take effect, for as long as the host keeps the file
     * it published.
     *
     * Recursion stops at a list on either side, because a list is a value, not a structure.
     * `array_merge_recursive()` concatenates lists and `array_replace_recursive()` merges them by
     * index: a host narrowing a shipped `['web', 'api']` to `['admin']` gets back
     * `['web', 'api', 'admin']` from the first and `['admin', 'api']` from the second. Both hand
     * back an entry the host removed, which on an allowlist is a security regression. Here the
     * published list stands as written.
     *
     * `array_is_list([])` is true, which is the behavior wanted here: an empty array is a host
     * saying "none", and descending into it could only re-introduce what it emptied.
     *
     * Recursion stops at a catalog as well, a section named in CATALOG_SECTIONS. Its keys are the
     * host's, so a key missing from the published catalog is one the host removed, and laying the
     * default under it would hand that entry back.
     *
     * A host with a cached config is not merged at runtime: the cache already holds the merge
     * `config:cache` did when it ran. A key a later release adds is missing from it until the host
     * caches again, so a package that reads a nested key needs its read-site fallback to agree with
     * the shipped default.
     */
    private function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $shipped = require $path;

        $repository = $this->app->make(Repository::class);
        $existing = $repository->get($key);

        // Both sides come off disk or out of the container, so neither is provably
        // string-keyed here — a config array is just an array. The recursion below is
        // written for exactly that: it asks whether a value is a list, never whether a key
        // is a string.
        $repository->set($key, $this->mergeConfigSections(
            is_array($shipped) ? $shipped : [],
            is_array($existing) ? $existing : [],
            $key.'.',
            self::CATALOG_SECTIONS,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $shipped
     * @param  array<array-key, mixed>  $published
     * @param  string  $path  the dotted path of the two maps, so a catalog is recognized at any depth
     * @param  list<string>  $catalogs  the dotted paths of the catalogs, which the merge takes as published
     * @return array<array-key, mixed>
     */
    private function mergeConfigSections(array $shipped, array $published, string $path = '', array $catalogs = []): array
    {
        foreach ($shipped as $key => $value) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $value;

                continue;
            }

            // Recurse only where both sides are maps and the section is no catalog. If either is a
            // list, or the published value is a scalar or an explicit null, what the host wrote
            // stands. An explicit null is a value like any other here, which keeps "write => null
            // to switch this off" working exactly as a shipped config file describes it.
            if (is_array($value) && is_array($published[$key]) && ! array_is_list($value) && ! array_is_list($published[$key]) && ! in_array($path.$key, $catalogs, true)) {
                $published[$key] = $this->mergeConfigSections($value, $published[$key], $path.$key.'.', $catalogs);
            }
        }

        return $published;
    }

    private function registerPublishing(): void
    {
        // Resolve publish targets through the Application contract's path methods
        // (available via illuminate/contracts), not the config_path() global helper.
        // It is a Foundation helper,
        // shipped only with laravel/framework — which this lean package does not
        // require — so the helper form would freeze a wrong dependency contract and
        // fatal in a non-Foundation host. The method form is behavior-identical.
        //
        // The group carries the bare 'http-api' umbrella tag on top of its specific one, the tag
        // convention Laravel's official package skeleton establishes.
        $this->publishes([
            __DIR__.'/../config/http-api.php' => $this->app->configPath('http-api.php'),
        ], ['http-api', 'http-api-config']);
    }
}
