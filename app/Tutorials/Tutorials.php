<?php

namespace App\Tutorials;

use App\Enums\Role;
use App\Support\CurrentTenant;
use App\Support\Industry;
use Illuminate\Support\Collection;

/**
 * The in-app tutorial ("Tutorial" in the sidebar): the chapters in resources/tutorials, kept in the repository
 * (never in the database). A chapter shows only to the kinds of business it names (config/industries.php keys),
 * an admin chapter only to admins, and a "leader" chapter to admins and team leaders.
 */
final class Tutorials
{
    /** Group key => label, in menu order. "properti" shows only for a property agency. */
    public const GROUPS = [
        'mulai' => 'Mulai',
        'lead' => 'Lead masuk',
        'percakapan' => 'Percakapan dan AI',
        'penjualan' => 'Penjualan',
        'properti' => 'Properti dan listing',
        'pengiriman' => 'Pengiriman',
        'iklan' => 'Iklan dan laporan',
        'akun' => 'Pengaturan akun',
    ];

    /** @var array<string, Chapter>|null */
    private static ?array $all = null;

    /** @return array<string, Chapter> every chapter, by slug, in reading order */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $chapters = [];

        foreach (glob(resource_path('tutorials/*.php')) ?: [] as $file) {
            $slug = basename($file, '.php');
            $chapters[$slug] = Chapter::fromArray($slug, require $file);
        }

        $groups = array_keys(self::GROUPS);
        uasort($chapters, fn (Chapter $a, Chapter $b) => [array_search($a->group, $groups, true), $a->order, $a->title]
            <=> [array_search($b->group, $groups, true), $b->order, $b->title]);

        return self::$all = $chapters;
    }

    /** Forgets what was read (tests that add a chapter file). */
    public static function flush(): void
    {
        self::$all = null;
    }

    /** @return Collection<string, Chapter> the chapters the current user may read, in reading order */
    public static function visible(?Industry $industry = null, ?Role $role = null): Collection
    {
        $industry ??= Industry::current();
        $role ??= app(CurrentTenant::class)->roleOf(auth()->user());

        return collect(self::all())->filter(fn (Chapter $c) => $c->for($industry) && $c->readableBy($role));
    }

    public static function find(string $slug, ?Industry $industry = null, ?Role $role = null): ?Chapter
    {
        return self::visible($industry, $role)->get($slug);
    }

    /** @return Collection<string, Chapter> the visible chapters whose words hold every word of $query */
    public static function search(string $query, ?Industry $industry = null, ?Role $role = null): Collection
    {
        $words = array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: []);

        return self::visible($industry, $role)->filter(fn (Chapter $c) => collect($words)->every(fn ($w) => str_contains($c->text(), $w)));
    }

    /** @return array{0: ?Chapter, 1: ?Chapter} the chapters before and after $slug, as the reader sees them */
    public static function neighbours(string $slug, ?Industry $industry = null, ?Role $role = null): array
    {
        $slugs = self::visible($industry, $role)->keys()->all();
        $i = array_search($slug, $slugs, true);

        if ($i === false) {
            return [null, null];
        }

        return [self::find($slugs[$i - 1] ?? '', $industry, $role), self::find($slugs[$i + 1] ?? '', $industry, $role)];
    }

    /** The visible chapter a page links to with "Lihat tutorial", from its render hook scopes (page and resource). */
    public static function forScopes(array $scopes): ?Chapter
    {
        return self::visible()->first(fn (Chapter $c) => array_intersect($c->pages, $scopes) !== []);
    }

    /** @return array<string, string> group key => label, for the current agency */
    public static function groups(?Industry $industry = null): array
    {
        $industry ??= Industry::current();

        return $industry->key === 'properti' ? self::GROUPS : array_diff_key(self::GROUPS, ['properti' => 1]);
    }
}
