<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The owner's panel looks across all agencies, so no agency may be "current" in it. */
class NoCurrentTenant
{
    public function __construct(private CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->set(null);

        return $next($request);
    }
}
