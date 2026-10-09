<?php

namespace App\Livewire;

use App\Mail\SupportMessageReceived;
use App\Models\SupportThread;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * The floating support chat in the agency panel. Each user has one running
 * conversation with the platform owner's team; sending into a finished one
 * reopens it. The tenant scope keeps every agency's chats apart, and the
 * user_id filter keeps colleagues' chats private from each other.
 */
class SupportChat extends Component
{
    public bool $open = false;

    public string $body = '';

    public ?string $notice = null;

    private function thread(): ?SupportThread
    {
        return SupportThread::query()->where('user_id', auth()->id())->latest('id')->first();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        $this->notice = null;

        if ($this->open && ($thread = $this->thread())?->user_unread) {
            $thread->forceFill(['user_unread' => false])->save();
        }
    }

    public function send(): void
    {
        $tenant = app(CurrentTenant::class)->get();
        $body = trim($this->body);

        if ($tenant === null || $body === '') {
            return;
        }

        $key = 'support-chat:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            $this->notice = 'Terlalu cepat. Tunggu sebentar lalu kirim lagi.';

            return;
        }

        RateLimiter::hit($key, 60);

        $body = mb_substr($body, 0, 2000);
        $thread = $this->thread() ?? SupportThread::create([
            'tenant_id' => $tenant->getKey(), 'user_id' => auth()->id(), 'subject' => mb_substr(preg_replace('/\s+/', ' ', $body), 0, 100),
        ]);
        $message = $thread->addMessage($body, false, auth()->user());
        $this->body = '';
        $this->notice = null;

        if ($to = config('jitu.support_email')) {
            try {
                Mail::to($to)->send(new SupportMessageReceived($thread, $message));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function render(): View
    {
        $thread = $this->thread();

        // An open window shows new answers at once, so they are read.
        if ($this->open && $thread?->user_unread) {
            $thread->forceFill(['user_unread' => false])->save();
            $thread->user_unread = false;
        }

        return view('livewire.support-chat', [
            'thread' => $thread,
            'messages' => $this->open && $thread ? $thread->messages()->get() : collect(),
            'unread' => ! $this->open && $thread?->user_unread,
            'whatsapp' => preg_replace('/\D/', '', (string) config('jitu.support_whatsapp')),
        ]);
    }
}
