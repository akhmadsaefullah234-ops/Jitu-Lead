<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Berhenti menerima email ini" from a trial email. The link is signed per user. Opening it asks for a click
 * (mail scanners open links on their own); the POST, from that button or a mail app's one-click unsubscribe, does it.
 */
class TrialEmailUnsubscribeController extends Controller
{
    public function show(Request $request, User $user): View
    {
        return view('trial-emails.unsubscribe', ['done' => $user->trial_emails_unsubscribed_at !== null, 'action' => $request->fullUrl()]);
    }

    public function store(User $user): View
    {
        if ($user->trial_emails_unsubscribed_at === null) {
            $user->forceFill(['trial_emails_unsubscribed_at' => now()])->save();
        }

        return view('trial-emails.unsubscribe', ['done' => true, 'action' => null]);
    }
}
