<?php

namespace App\Ai\Facts;

use App\Models\AiFact;
use App\Models\AiKnowledgeItem;
use App\Models\Property;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The agency's structured property facts: what the "Isi properti" wizard saved, what a document said once the owner
 * approved it, and where they disagree. The AI's standard knowledge entries (source "fakta") are rewritten from the
 * approved facts after every change; a fact two sources still disagree on is left out until the owner decides.
 * Must run inside the tenant.
 */
final class Facts
{
    public const KNOWLEDGE_SOURCE = 'fakta';

    /** @return Collection<int, AiFact> */
    public static function approved(): Collection
    {
        return AiFact::query()->where('status', AiFact::APPROVED)->orderBy('id')->get();
    }

    /** "melati" for " Tipe Melati ", so one type is one subject whatever the spelling. */
    public static function subjectKey(?string $subject): string
    {
        $text = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $subject)));

        return trim((string) preg_replace('/^tipe\s+/u', '', $text));
    }

    /**
     * Groups of facts about the same thing with different values (approved or waiting), each for the owner to decide.
     *
     * @return list<array{subject: ?string, key: string, facts: Collection<int, AiFact>}>
     */
    public static function conflicts(): array
    {
        return AiFact::query()->whereIn('status', [AiFact::APPROVED, AiFact::PENDING])->orderBy('id')->get()
            ->groupBy(fn (AiFact $f) => self::subjectKey($f->subject).'|'.$f->key)
            ->filter(fn (Collection $group) => $group->map(fn (AiFact $f) => FactFormat::comparable($f->key, $f->value))->unique()->count() > 1)
            ->map(fn (Collection $group) => ['subject' => $group->first()->subject, 'key' => $group->first()->key, 'facts' => $group->values()])
            ->values()->all();
    }

    /** @return list<int> ids of facts that are in a conflict */
    public static function conflictIds(): array
    {
        return collect(self::conflicts())->flatMap(fn (array $c) => $c['facts']->modelKeys())->all();
    }

    /**
     * What the wizard holds now: the approved value of every fact (the newest when there is more than one).
     *
     * @return array{project: array<string, string>, types: list<array<string, mixed>>}
     */
    public static function form(): array
    {
        $facts = self::approved();
        $project = $facts->whereNull('subject')->mapWithKeys(fn (AiFact $f) => [$f->key => $f->value])->all();
        $project['pay_options'] = PaymentModel::decode($project['pay_options'] ?? null);
        $types = $facts->whereNotNull('subject')->groupBy(fn (AiFact $f) => self::subjectKey($f->subject))
            ->map(fn (Collection $g) => ['name' => $g->last()->subject, 'property_id' => $g->whereNotNull('property_id')->last()?->property_id]
                + $g->mapWithKeys(fn (AiFact $f) => [$f->key => $f->value])->all())
            ->values()->all();

        return ['project' => $project, 'types' => $types];
    }

    /**
     * Saves what the owner typed in the wizard. Each type becomes (or updates) a property in the catalogue, and an
     * approved fact the owner has just changed is replaced: the owner's own entry is the decision. A document's fact
     * still waiting for review stays, so a disagreement with it is shown.
     *
     * @param  array{project?: array<string, mixed>, types?: list<array<string, mixed>>}  $data
     */
    public static function saveWizard(array $data): void
    {
        DB::transaction(function () use ($data) {
            $project = $data['project'] ?? [];
            $project['pay_options'] = PaymentModel::encode($project['pay_options'] ?? []);
            $rows = [];

            foreach (FactKeys::PROJECT as $key => $_) {
                if (($value = FactFormat::clean($key, $project[$key] ?? null)) !== null) {
                    $rows[] = ['subject' => null, 'property_id' => null, 'key' => $key, 'value' => $value];
                }
            }

            foreach ($data['types'] ?? [] as $type) {
                $name = trim((string) ($type['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $property = self::catalogue($type, $project);

                foreach (FactKeys::TYPE as $key => $_) {
                    if (($value = FactFormat::clean($key, $type[$key] ?? null)) !== null) {
                        $rows[] = ['subject' => $name, 'property_id' => $property->getKey(), 'key' => $key, 'value' => $value];
                    }
                }
            }

            // What the wizard now shows replaces everything approved (it was filled from those facts). A value that did
            // not change keeps its fact, and with it where it came from (a document, say); the rest is the owner's entry.
            $kept = [];
            $approved = self::approved();

            foreach ($rows as $row) {
                $same = $approved->first(fn (AiFact $f) => ! in_array($f->getKey(), $kept, true) && $f->key === $row['key']
                    && self::subjectKey($f->subject) === self::subjectKey($row['subject'])
                    && FactFormat::comparable($f->key, $f->value) === FactFormat::comparable($row['key'], $row['value']));

                if ($same !== null) {
                    $same->forceFill(['subject' => $row['subject'], 'property_id' => $row['property_id']])->save();
                    $kept[] = $same->getKey();

                    continue;
                }

                $kept[] = AiFact::query()->create($row + ['source' => AiFact::WIZARD, 'status' => AiFact::APPROVED])->getKey();
            }

            AiFact::query()->where('status', AiFact::APPROVED)->whereKeyNot($kept)->update(['status' => AiFact::REJECTED]);

            self::rebuild();

            if (($tenant = app(CurrentTenant::class)->get()) !== null) {
                PaymentDefaults::adapt($tenant, PaymentModel::current());
            }
        });
    }

    /** The catalogue property of one unit type: the one linked before, else one with the same type name, else a new one. */
    private static function catalogue(array $type, array $project): Property
    {
        $name = trim((string) $type['name']);
        $property = filled($type['property_id'] ?? null) ? Property::query()->find($type['property_id']) : null;
        $property ??= Property::query()->get()->first(fn (Property $p) => self::subjectKey($p->property_type) === self::subjectKey($name));
        $projectName = trim((string) ($project['project'] ?? ''));
        $price = FactFormat::clean('price', $type['price'] ?? null);
        $status = ['Tersedia' => 'available', 'Inden' => 'available', 'Terjual' => 'sold'][$type['availability'] ?? ''] ?? null;

        $details = array_filter([
            'Luas bangunan' => ($v = FactFormat::clean('building_area', $type['building_area'] ?? null)) ? "$v m2" : null,
            'Luas tanah' => ($v = FactFormat::clean('land_area', $type['land_area'] ?? null)) ? "$v m2" : null,
            'Kamar tidur' => FactFormat::clean('bedrooms', $type['bedrooms'] ?? null),
            'Kamar mandi' => FactFormat::clean('bathrooms', $type['bathrooms'] ?? null),
            'Ketersediaan' => filled($type['availability'] ?? null) ? (string) $type['availability'] : null,
        ]);

        $values = array_filter([
            'name' => mb_substr(trim(($projectName !== '' ? $projectName.' - ' : '').'Tipe '.preg_replace('/^tipe\s+/iu', '', $name)), 0, 120),
            'property_type' => mb_substr($name, 0, 120),
            'location' => filled($project['location'] ?? null) ? mb_substr((string) $project['location'], 0, 120) : null,
            'developer' => filled($project['developer'] ?? null) ? mb_substr((string) $project['developer'], 0, 120) : null,
            'price_from' => $price !== null ? (int) $price : null,
            'status' => $status,
        ], fn ($v) => $v !== null);

        if ($property === null) {
            return Property::query()->create($values + ['kind' => 'primary', 'details' => $details ?: null]);
        }

        $property->fill($values)->forceFill(['details' => array_merge((array) $property->details, $details) ?: null])->save();

        return $property;
    }

    /** A document's fact the owner approves; the unit type it names is linked to its catalogue property when there is one. */
    public static function approve(AiFact $fact): void
    {
        if ($fact->subject !== null && $fact->property_id === null) {
            $property = Property::query()->get()->first(fn (Property $p) => self::subjectKey($p->property_type) === self::subjectKey($fact->subject)
                || str_ends_with(self::subjectKey($p->name), self::subjectKey($fact->subject)));
            $fact->property_id = $property?->getKey();
        }

        $fact->forceFill(['status' => AiFact::APPROVED])->save();
        self::rebuild();
    }

    public static function reject(AiFact $fact): void
    {
        $fact->forceFill(['status' => AiFact::REJECTED])->save();
        self::rebuild();
    }

    /** The owner decides a conflict: this value is kept, every other value for the same thing is rejected. */
    public static function choose(AiFact $fact): void
    {
        DB::transaction(function () use ($fact) {
            AiFact::query()->whereIn('status', [AiFact::APPROVED, AiFact::PENDING])->where('key', $fact->key)->whereKeyNot($fact->getKey())->get()
                ->filter(fn (AiFact $other) => self::subjectKey($other->subject) === self::subjectKey($fact->subject))
                ->each(fn (AiFact $other) => $other->forceFill(['status' => AiFact::REJECTED])->save());

            self::approve($fact);
        });
    }

    /**
     * Rewrites the standard knowledge entries from the approved facts: one for the project, one for payment, one for
     * the survey, one for contact, one for the rent estimate, and one per unit type linked to its property.
     */
    public static function rebuild(): void
    {
        $conflicted = self::conflictIds();
        $facts = self::approved()->reject(fn (AiFact $f) => in_array($f->getKey(), $conflicted, true));
        $project = $facts->whereNull('subject')->keyBy('key');
        $value = fn (string $key) => isset($project[$key]) ? FactFormat::show($key, $project[$key]->value) : null;
        $name = $value('project');
        $entries = [];

        $entries[] = [null, 'Proyek '.($name ?? 'properti'), self::lines([
            'Nama proyek' => $name, 'Developer' => $value('developer'), 'Lokasi' => $value('location'),
            'Legalitas' => $value('legal'), 'Serah terima' => $value('handover'),
        ])];
        // The payment model when the agency filled it, else the free text from before it (or from a document).
        $payment = PaymentModel::from($facts->whereNull('subject'));
        $entries[] = [null, $payment->filled() ? 'Model pembayaran' : 'Cara bayar', self::lines($payment->filled()
            ? $payment->lines() + ['Catatan cara bayar' => $value('payment_methods'), 'Catatan DP' => $value('down_payment'), 'Catatan tenor' => $value('tenor')]
            : ['Cara bayar' => $value('payment_methods'), 'Booking fee' => $value('booking_fee'), 'Uang muka (DP)' => $value('down_payment'), 'Tenor cicilan' => $value('tenor')])];
        $entries[] = [null, 'Jam dan lokasi survei', self::lines(['Jam survei' => $value('survey_hours'), 'Lokasi survei' => $value('survey_location') ?? $value('location')])];
        $entries[] = [null, 'Kontak', self::lines(['Kontak' => $value('contact')])];

        if (($rent = $value('rent_estimate')) !== null) {
            $entries[] = [null, 'Perkiraan sewa', "Perkiraan sewa: {$rent}. Ini perkiraan, bukan janji hasil sewa atau investasi."];
        }

        foreach ($facts->whereNotNull('subject')->groupBy(fn (AiFact $f) => self::subjectKey($f->subject)) as $type) {
            $byKey = $type->keyBy('key');
            $label = trim((string) preg_replace('/^tipe\s+/iu', '', (string) $type->last()->subject));
            $entries[] = [$type->whereNotNull('property_id')->last()?->property_id, 'Tipe '.$label, self::lines(
                collect(FactKeys::TYPE)->mapWithKeys(fn (array $def, string $key) => [$def[0] => isset($byKey[$key]) ? FactFormat::show($key, $byKey[$key]->value) : null])->all()
            )];
        }

        AiKnowledgeItem::query()->where('source', self::KNOWLEDGE_SOURCE)->delete();

        foreach ($entries as [$propertyId, $title, $content]) {
            if ($content !== '') {
                AiKnowledgeItem::query()->create(['title' => mb_substr($title, 0, 120), 'content' => $content, 'source' => self::KNOWLEDGE_SOURCE,
                    'active' => true, 'property_id' => $propertyId]);
            }
        }
    }

    /** @param  array<string, ?string>  $values */
    private static function lines(array $values): string
    {
        return collect($values)->filter(fn (?string $v) => filled($v))->map(fn (string $v, string $label) => $label.': '.rtrim($v, '. ').'.')->implode("\n");
    }
}
