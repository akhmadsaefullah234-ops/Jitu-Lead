<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the tenant Filament resolved from the URL to the model layer, so
 * every tenant-owned query in this request is scoped to it.
 */
class SetCurrentTenant
{
    public function __construct(private CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->set(Filament::getTenant());

        return $next($request);
    }
}
