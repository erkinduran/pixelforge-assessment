<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only admins (users.is_admin) pass. Everyone else gets 403, a guest is stopped earlier by `auth`. */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin === true, 403);

        return $next($request);
    }
}
