<?php

namespace App\Filament\Pages;

use App\Support\Industry;
use App\Tutorials\Chapter;
use App\Tutorials\Tutorials;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "Tutorial": step-by-step guides with screenshots, for every role. The chapters live in resources/tutorials
 * (App\Tutorials\Tutorials); a property agency sees property chapters and a shop sees shop chapters, and admin
 * chapters are hidden from agents and team leaders.
 */
class Tutorial extends Page
{
    protected string $view = 'filament.pages.tutorial';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Tutorial';

    protected static ?string $title = 'Tutorial';

    protected static ?int $navigationSort = 90;

    /** The chapter being read; empty for the list of chapters. */
    #[Url(as: 'bab')]
    public string $chapter = '';

    #[Url(as: 'cari')]
    public string $search = '';

    public static function url(?Chapter $chapter = null): string
    {
        return static::getUrl($chapter ? ['bab' => $chapter->slug] : [], tenant: Filament::getTenant());
    }

    public function getTitle(): string
    {
        return $this->current()?->title ?? 'Tutorial';
    }

    public function getBreadcrumbs(): array
    {
        return $this->current() ? [static::url() => 'Tutorial', $this->current()->title] : [];
    }

    private function current(): ?Chapter
    {
        return $this->chapter === '' ? null : Tutorials::find($this->chapter);
    }

    public function getViewData(): array
    {
        $chapter = $this->current();
        $visible = Tutorials::visible();
        $found = trim($this->search) === '' ? $visible : Tutorials::search($this->search);
        [$previous, $next] = $chapter ? Tutorials::neighbours($chapter->slug) : [null, null];

        return [
            'industry' => Industry::current(),
            'groups' => Tutorials::groups(),
            'chapters' => $found->groupBy(fn (Chapter $c) => $c->group),
            'all' => $visible->groupBy(fn (Chapter $c) => $c->group),
            'current' => $chapter,
            'missing' => $this->chapter !== '' && $chapter === null,
            'previous' => $previous,
            'next' => $next,
        ];
    }
}
