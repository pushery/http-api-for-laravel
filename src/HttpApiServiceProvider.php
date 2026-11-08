<?php

declare(strict_types=1);

namespace Pushery\HttpApi;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\HttpApi\Idempotency\AuthenticatedUserScope;
use Pushery\HttpApi\Idempotency\ResolvesIdempotencyScope;
use Pushery\HttpApi\Problems\ProblemRenderer;
use Pushery\HttpApi\Support\LanguageFallbackLoader;
use Throwable;

final class HttpApiServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/http-api.php', 'http-api');

        // Bound only where the application has not bound its own, so a host that knows its callers by an API key or a
        // signed service replaces the default in its own provider, whichever of the two registers first.
        $this->app->bindIf(ResolvesIdempotencyScope::class, AuthenticatedUserScope::class);

        // A regional locale reads this package's catalog of its language, `pt-BR` from `pt`, in this package's
        // namespace only. Registered here so it wraps the loader before the translator is built from it.
        $this->app->extend('translation.loader', static fn (Loader $loader): Loader => new LanguageFallbackLoader($loader, 'http-api'));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'http-api');
        $this->loadRoutesFrom(__DIR__.'/../routes/http-api.php');
        $this->registerProblemRenderer();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    /**
     * Hand the problem renderer to Laravel's exception handler, which asks it before rendering a failure itself.
     *
     * The switches are read when a failure is rendered, not here, so a test or a host that changes them at runtime gets
     * what it set. A handler without render callbacks, one an application wrote from scratch against the contract,
     * gets nothing, and the application renders problems itself through {@see ProblemRenderer::render()}.
     *
     * Registered on whichever handler the application resolves, so one it binds after this provider boots is asked
     * too.
     */
    private function registerProblemRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            // A handler of the application's own, without Laravel's render callbacks, calls ProblemRenderer::render() itself.
            if (method_exists($handler, 'renderable')) {
                $handler->renderable(function (Throwable $failure, Request $request): mixed {
                    $config = $this->app->make(Repository::class);

                    if ($config->get('http-api.enabled') === false || $config->get('http-api.problems.enabled') === false) {
                        return null;
                    }

                    return $this->app->make(ProblemRenderer::class)->render($failure, $request);
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
     * A host with a cached config is never merged at all, by the framework's design. For those
     * installations the published file is the whole truth, so a package that reads a nested key
     * needs its read-site fallback to agree with the shipped default. The two are halves of one
     * guarantee.
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
        ));
    }

    /**
     * @param  array<array-key, mixed>  $shipped
     * @param  array<array-key, mixed>  $published
     * @return array<array-key, mixed>
     */
    private function mergeConfigSections(array $shipped, array $published): array
    {
        foreach ($shipped as $key => $value) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $value;

                continue;
            }

            // Recurse only where both sides are maps. If either is a list, or the published
            // value is a scalar or an explicit null, what the host wrote stands. An explicit
            // null is a value like any other here, which keeps "write => null to switch this
            // off" working exactly as a shipped config file describes it.
            if (is_array($value) && is_array($published[$key]) && ! array_is_list($value) && ! array_is_list($published[$key])) {
                $published[$key] = $this->mergeConfigSections($value, $published[$key]);
            }
        }

        return $published;
    }

    private function registerPublishing(): void
    {
        // Resolve publish targets through the Application contract's path methods
        // (available via illuminate/contracts), not the config_path()/lang_path() global
        // helpers. Those are Foundation helpers,
        // shipped only with laravel/framework — which this lean package does not
        // require — so the helper form would freeze a wrong dependency contract and
        // fatal in a non-Foundation host. The method form is behavior-identical.
        //
        // Each group carries the bare 'http-api' umbrella tag on top of its specific one, so
        // `vendor:publish --tag="http-api"` publishes the configuration and the translations at
        // once, the tag convention Laravel's official package skeleton establishes.
        $this->publishes([
            __DIR__.'/../config/http-api.php' => $this->app->configPath('http-api.php'),
        ], ['http-api', 'http-api-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/http-api'),
        ], ['http-api', 'http-api-lang']);
    }
}
