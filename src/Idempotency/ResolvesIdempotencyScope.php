<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Http\Request;

/**
 * Whose `Idempotency-Key` a request carries.
 *
 * A key is unique only for the client that chose it, so two clients that happen to send the same key must never meet
 * each other's answer. The scope says who sent a request: a user, an API key, a signed service, a tenant. Null means
 * nothing identifies the sender, and the middleware refuses to guess, because a guess such as the client's address
 * would hand one client's answer to another behind the same proxy.
 */
interface ResolvesIdempotencyScope
{
    public function scopeOf(Request $request): ?string;
}
