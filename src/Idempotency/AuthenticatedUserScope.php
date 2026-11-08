<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The default scope: the user a request is authenticated as, by class and identifier, so a user and an API client of
 * another model with the same id stay apart. A request nobody is authenticated for has no scope here.
 */
final class AuthenticatedUserScope implements ResolvesIdempotencyScope
{
    public function scopeOf(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) && (string) $identifier !== '' ? $user::class.':'.$identifier : null;
    }
}
