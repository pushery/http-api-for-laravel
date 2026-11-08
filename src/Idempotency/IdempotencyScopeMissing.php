<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use LogicException;
use Pushery\HttpApi\Problems\Problem;
use Pushery\HttpApi\Problems\ProvidesProblem;

/**
 * A request carries an `Idempotency-Key`, and nothing says who sent it.
 *
 * That is a setting of the application, not a mistake of the client: the route lets an unauthenticated caller write,
 * and no scope resolver was bound that knows that caller. It is thrown rather than answered quietly, so it reaches the
 * reporters, and a client reads a `500` that says the key could not be held.
 */
final class IdempotencyScopeMissing extends LogicException implements ProvidesProblem
{
    public static function on(string $path): self
    {
        return new self(sprintf(
            'The request to [%s] carries an Idempotency-Key, and the bound %s finds no scope for it. Bind a resolver that knows the caller of this route.',
            $path,
            ResolvesIdempotencyScope::class,
        ));
    }

    public function toProblem(): Problem
    {
        $container = Container::getInstance();
        $base = $container->make(Repository::class)->get('http-api.problems.type_base');
        $translator = $container->make(Translator::class);
        $title = $translator->get('http-api::messages.idempotency-scope-missing.title');
        $detail = $translator->get('http-api::messages.idempotency-scope-missing.detail');

        return new Problem(
            500,
            type: (is_string($base) ? $base : '').'idempotency-scope-missing',
            title: is_string($title) ? $title : null,
            detail: is_string($detail) ? $detail : null,
        );
    }
}
