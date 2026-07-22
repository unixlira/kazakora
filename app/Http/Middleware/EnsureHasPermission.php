<?php

namespace App\Http\Middleware;

use App\Support\Rbac\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(Permissions::has($request->user(), $permission), 403);

        return $next($request);
    }
}
