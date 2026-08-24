<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SIGNATURE THAT IS PRESENT IS A SIGNATURE THAT IS CHECKED.
 *
 * ⚠️ WITHOUT THIS FILTER, "EXPIRING" WOULD BE A LIE. The route is served inside the host's
 * group, most often behind authentication: a signed link whose expiry has passed would keep
 * working for anyone logged in, and the package would hand out URLs that display a use-by date
 * without having one.
 *
 * ⚠️ AND THE SIGNATURE IS NOT REQUIRED HERE, DELIBERATELY. A back-office user clicking a link
 * does not need one: the route group and the scoping do the work. A host that does want to
 * serve public expiring links removes the authentication and adds `signed` to its middleware —
 * that is its call, and it is written in its configuration rather than guessed here.
 *
 * ⚠️ THIS FILTER IS THEREFORE NOT A SECURITY BOUNDARY, and must not be read as one: whoever
 * wants to do without a signature simply omits it. What it guarantees is narrower and
 * verifiable — a supplied signature that is worthless does not pass in silence.
 */
final class ValidateSignatureWhenPresent
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query('signature') === null) {
            return $next($request);
        }

        if (! $request->hasValidSignature()) {
            throw new InvalidSignatureException();
        }

        return $next($request);
    }
}
