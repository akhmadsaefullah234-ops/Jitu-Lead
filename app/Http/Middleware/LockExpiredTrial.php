<?php

namespace App\Http\Middleware;

use App\Billing\TrialLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every page of an agency whose trial ended unpaid to the plan page (TrialLock). Runs on page loads only;
 * changes made through components are already refused by the read-only checks in the policies and actions.
 */
class LockExpiredTrial
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TrialLock::applies()) {
            return $next($request);
        }

        foreach (TrialLock::openPages() as $page) {
            if ($request->routeIs($page::getRouteName())) {
                return $next($request);
            }
        }

        return redirect()->to(TrialLock::homeUrl());
    }
}
