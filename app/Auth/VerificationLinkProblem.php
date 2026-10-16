<?php

namespace App\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Turns the bare 403 of a wrong email verification link into a page that says what happened: the link
 * belongs to another account (someone else is signed in), or it expired or no longer matches.
 */
class VerificationLinkProblem
{
    public static function render(\Throwable $e, Request $request): ?Response
    {
        $route = $request->route();

        if (! $route || ! str_ends_with((string) $route->getName(), 'auth.email-verification.verify')) {
            return null;
        }

        $current = $request->user();

        if ($e instanceof InvalidSignatureException) {
            return self::page('expired', $current, null, $request, 410);
        }

        if (! $e instanceof AuthorizationException && ! ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403)) {
            return null;
        }

        $owner = User::query()->find($route->parameter('id'));

        if (! $owner || ($current && $owner->is($current))) {
            // Same account but the email changed since the link was sent: the link is simply no longer valid.
            return self::page('expired', $current, null, $request, 410);
        }

        return self::page('other', $current, $owner, $request, 403);
    }

    public static function page(string $kind, ?User $current, ?User $owner, Request $request, int $status = 200): Response
    {
        return response()->view('auth.verification-problem', [
            'kind' => $kind,
            'current' => $current,
            'ownerEmail' => $owner ? self::mask($owner->email) : null,
            'verifyUrl' => $request->fullUrl(),
            'loginUrl' => Filament::getPanel('app')->getLoginUrl(),
        ], $status);
    }

    /** "joko@gmail.com" becomes "j***@gmail.com". */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***'.($domain !== '' ? '@'.$domain : '');
    }
}
