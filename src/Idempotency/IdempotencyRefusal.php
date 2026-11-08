<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Pushery\HttpApi\Problems\Problem;
use Pushery\HttpApi\Problems\ProvidesProblem;
use RuntimeException;

/**
 * Why a request with an `Idempotency-Key` was not let through, as the statuses the draft names: `400` for a key that
 * is missing where it is required or is malformed, `409` while the first request under the key is still running or
 * when its answer could not be kept, and `422` for the same key with another request.
 */
final class IdempotencyRefusal extends RuntimeException implements ProvidesProblem
{
    private function __construct(
        public readonly string $problem,
        public readonly int $status,
    ) {
        parent::__construct($problem);
    }

    public static function missing(): self
    {
        return new self('idempotency-key-missing', 400);
    }

    public static function malformed(): self
    {
        return new self('idempotency-key-malformed', 400);
    }

    public static function inFlight(): self
    {
        return new self('idempotency-key-in-flight', 409);
    }

    public static function notKept(): self
    {
        return new self('idempotency-answer-not-kept', 409);
    }

    public static function reused(): self
    {
        return new self('idempotency-key-reused', 422);
    }

    public function toProblem(): Problem
    {
        $container = Container::getInstance();
        $base = $container->make(Repository::class)->get('http-api.problems.type_base');
        $translator = $container->make(Translator::class);
        $title = $translator->get('http-api::messages.'.$this->problem.'.title');
        $detail = $translator->get('http-api::messages.'.$this->problem.'.detail');

        return new Problem(
            $this->status,
            type: (is_string($base) ? $base : '').$this->problem,
            title: is_string($title) ? $title : null,
            detail: is_string($detail) ? $detail : null,
        );
    }
}
