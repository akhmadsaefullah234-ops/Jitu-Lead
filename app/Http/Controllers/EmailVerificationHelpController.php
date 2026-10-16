<?php

namespace App\Http\Controllers;

use App\Auth\VerificationLinkProblem;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/** The two buttons of the "wrong verification link" page. */
class EmailVerificationHelpController
{
    /** Signs out and sends the person to the login page; after logging in they land on the verification link again. */
    public function switchAccount(Request $request): RedirectResponse
    {
        $url = (string) $request->input('url');
        $path = (string) parse_url($url, PHP_URL_PATH);

        // Only the signed verification link itself may be remembered, never an arbitrary address.
        abort_unless(str_contains($path, '/email-verification/verify/') && URL::hasValidSignature(Request::create($url)), 422);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', $url);

        return redirect()->to(Filament::getPanel('app')->getLoginUrl());
    }

    /** Sends a fresh verification email to the signed-in user. */
    public function resend(Request $request): Response
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return VerificationLinkProblem::page('verified', $user, null, $request);
        }

        $notification = app(VerifyEmail::class);
        $notification->url = Filament::getPanel('app')->getVerifyEmailUrl($user);
        $user->notify($notification);

        return VerificationLinkProblem::page('sent', $user, null, $request);
    }
}
