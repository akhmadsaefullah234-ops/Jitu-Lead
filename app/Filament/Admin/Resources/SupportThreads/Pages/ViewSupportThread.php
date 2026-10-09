<?php

namespace App\Filament\Admin\Resources\SupportThreads\Pages;

use App\Filament\Admin\Resources\SupportThreads\SupportThreadResource;
use App\Models\SupportThread;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** One conversation: the messages, a reply box, and a button to mark it done. */
class ViewSupportThread extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SupportThreadResource::class;

    protected string $view = 'filament.admin.support-thread';

    public string $reply = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->markRead();
    }

    public function getTitle(): string
    {
        return $this->record->subject;
    }

    private function markRead(): void
    {
        if ($this->record->staff_unread) {
            $this->record->forceFill(['staff_unread' => false])->save();
        }
    }

    public function send(): void
    {
        $body = trim($this->reply);

        if ($body === '') {
            return;
        }

        $this->record->addMessage(mb_substr($body, 0, 2000), true, auth()->user());
        $this->reply = '';
        $this->markRead();
    }

    public function close(): void
    {
        $this->record->forceFill(['status' => SupportThread::CLOSED])->save();
    }

    public function reopen(): void
    {
        $this->record->forceFill(['status' => SupportThread::OPEN])->save();
    }

    public function messages()
    {
        $this->record->refresh();
        $this->markRead();

        return $this->record->messages()->get();
    }
}
