<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Filament\Pages\Inbox;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\AutoShipments;
use App\Shipping\Shipments\InboxShipping;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Inbox's one shipping mark: a green "OK" on the card of a closing nobody has handled yet, the line with the
 * reason in the lead details, and the filter "Closing (N)".
 */
class InboxShippingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->inShop(fn () => CarrierAccount::query()->create(['provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'k', 'client_secret' => 's'], 'settings' => []]));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }

    private function stageId(string $key): int
    {
        return (int) $this->inShop(fn () => (new RetailStages)->stage($key)->getKey());
    }

    /** A lead with a chat; $wroteAt: the buyer's last message. */
    private function chat(string $name, string $stage = RetailStages::SHIPPING, array $extra = [], ?string $wroteAt = '-1 hour', ?string $lastAt = null): Lead
    {
        return $this->inShop(function () use ($name, $stage, $extra, $wroteAt, $lastAt) {
            $lead = Lead::create($extra + [
                'name' => $name, 'phone' => '0812'.str_pad((string) ++$this->n, 8, '0', STR_PAD_LEFT), 'stage_id' => $this->stageId($stage), 'stage_entered_at' => now()->subHours(3),
                'owner_id' => $this->adminOf($this->shop)->getKey(), 'payment_type' => 'cod',
            ]);
            WaConversation::query()->create(['lead_id' => $lead->id, 'phone' => $lead->phone, 'last_inbound_at' => $wroteAt ? now()->modify($wroteAt) : null,
                'last_message_at' => now()->modify($lastAt ?? $wroteAt ?? '-1 hour')]);

            return $lead;
        });
    }

    private function parcel(Lead $lead, array $attributes = []): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->create($attributes + [
            'lead_id' => $lead->getKey(), 'provider' => 'everpro', 'client_order_no' => 'JL-T-'.++$this->n, 'state' => Shipment::SHIPPED,
            'awb' => 'JNE00'.$this->n.'1234', 'courier' => 'JNE REG', 'status' => ShipmentStatus::AWB_CREATED, 'label_path' => 'labels/x.pdf',
        ]));
    }

    /** The rendered card of $lead in the chat list. */
    private function card(string $html, Lead $lead): string
    {
        $conversation = $this->inShop(fn () => WaConversation::query()->where('lead_id', $lead->id)->value('id'));
        $this->assertSame(1, preg_match('#<button[^>]*wire:key="c-'.$conversation.'".*?</button>#s', $html, $m), 'Card of '.$lead->name);

        return $m[0];
    }

    public function test_only_unhandled_closings_get_one_green_ok_mark(): void
    {
        $none = $this->chat('Perlu Resi', extra: ['auto_shipment_state' => AutoShipments::SKIPPED, 'auto_shipment_note' => 'Resi otomatis tidak dibuat: cara bayar kosong. Buat resi manual.']);
        $this->parcel($ready = $this->chat('Siap Cetak'));
        $this->parcel($printed = $this->chat('Sudah Cetak'), ['printed_at' => now()]);
        $this->parcel($failed = $this->chat('Gagal Antar'), ['printed_at' => now(), 'status' => ShipmentStatus::DELIVERY_FAILED, 'status_reason' => 'Rumah kosong']);
        $this->parcel($done = $this->chat('Selesai', RetailStages::DONE), ['printed_at' => now(), 'status' => ShipmentStatus::DELIVERED, 'final_at' => now()]);
        $confirmed = $this->chat('Sudah Konfirmasi', RetailStages::ORDER);
        $this->inShop(fn () => $confirmed->activities()->create(['type' => 'order_recorded', 'body' => 'Pesanan dicatat']));
        $plain = $this->chat('Tanya Saja', RetailStages::INQUIRY);

        $closings = $this->inShop(fn () => InboxShipping::closings(Lead::query()->get()));

        $this->assertEqualsCanonicalizing([$none->id, $ready->id, $confirmed->id], array_keys($closings));
        $this->assertSame(['OK · belum ada resi', 'cara bayar kosong'], [$closings[$none->id]['label'], $closings[$none->id]['reason']]);
        $this->assertSame('OK · resi belum dicetak', $closings[$ready->id]['label']);
        $this->assertSame('OK · belum ada resi', $closings[$confirmed->id]['label']);

        $html = Livewire::test(Inbox::class)->html();

        foreach ([$none, $ready, $confirmed] as $lead) {
            $card = $this->card($html, $lead);
            $this->assertSame(1, substr_count($card, 'class="wa-ok"'), $lead->name);
            $this->assertStringContainsString('title="'.InboxShipping::TOOLTIP.'"', $card);
            $this->assertStringNotContainsString('belum ada resi', $card, 'No reason line on the card.');
            $this->assertStringNotContainsString('Buat resi', $card);
        }

        foreach ([$printed, $failed, $done, $plain] as $lead) {
            $this->assertStringNotContainsString('wa-ok', $this->card($html, $lead), $lead->name);
        }

        // The other shipping marks and the location buttons are gone from the Inbox.
        foreach (['Dicetak · menunggu jemput', 'Gagal dijemput', 'Gagal antar', 'perlu dipilih', 'Lokasi pasti', 'Pilih lokasi', 'Perlu tindakan pengiriman', 'cara bayar kosong'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function test_the_ok_mark_does_not_make_the_card_taller(): void
    {
        $ok = $this->chat('Perlu Resi');
        $plain = $this->chat('Tanya Saja', RetailStages::INQUIRY);
        $html = Livewire::test(Inbox::class)->html();

        // Same rows: the mark sits in the name row next to the unread badge.
        $rows = fn (string $card) => substr_count($card, '<div');
        $this->assertSame($rows($this->card($html, $plain)), $rows($this->card($html, $ok)));
        $this->assertMatchesRegularExpression('#<div class="n">.*?class="wa-ok".*?</div>#s', $this->card($html, $ok));
    }

    public function test_the_lead_details_show_the_ok_line_and_why(): void
    {
        $lead = $this->chat('Perlu Resi', extra: ['auto_shipment_state' => AutoShipments::SKIPPED, 'auto_shipment_note' => 'Resi otomatis tidak dibuat: cara bayar kosong. Buat resi manual.']);

        Livewire::test(Inbox::class, ['leadId' => $lead->id])
            ->assertSeeHtml('class="wa-okline"')->assertSee('OK · belum ada resi')->assertSee('cara bayar kosong')
            ->assertDontSee('Pilih lokasi');
    }

    public function test_the_closing_filter_counts_right_and_keeps_the_newest_first(): void
    {
        $this->chat('Lama', wroteAt: '-5 hours');
        $this->parcel($this->chat('Siap Cetak', wroteAt: '-3 hours'));
        $this->parcel($this->chat('Sudah Cetak', wroteAt: '-2 hours'), ['printed_at' => now()]);
        $this->chat('Tanya Saja', RetailStages::INQUIRY, wroteAt: '-1 minute');

        $all = Livewire::test(Inbox::class);
        $this->assertSame(2, $all->viewData('closingCount'));
        $all->assertSee('Closing (2)');
        $this->assertSame(['Tanya Saja', 'Sudah Cetak', 'Siap Cetak', 'Lama'], $all->viewData('conversations')->pluck('lead.name')->all(), 'Newest message first.');

        $filtered = Livewire::test(Inbox::class)->set('filter', 'closing');
        $this->assertSame(['Siap Cetak', 'Lama'], $filtered->viewData('conversations')->pluck('lead.name')->all());
    }

    public function test_the_closing_filter_hides_when_there_is_none(): void
    {
        $this->chat('Tanya Saja', RetailStages::INQUIRY);

        $page = Livewire::test(Inbox::class)->set('filter', 'closing');
        $this->assertSame(0, $page->viewData('closingCount'));
        $page->assertDontSee('Closing (')->assertDontSeeHtml('class="wa-filter"');
        $this->assertCount(1, $page->viewData('conversations'), 'A stale "closing" filter falls back to all chats.');
    }

    public function test_the_mark_goes_once_the_label_is_printed(): void
    {
        $shipment = $this->parcel($lead = $this->chat('Siap Cetak'));
        $this->assertStringContainsString('wa-ok', $this->card(Livewire::test(Inbox::class)->html(), $lead));

        $this->inShop(fn () => $shipment->forceFill(['printed_at' => now()])->save());

        $page = Livewire::test(Inbox::class);
        $this->assertStringNotContainsString('wa-ok', $this->card($page->html(), $lead));
        $this->assertSame(0, $page->viewData('closingCount'));
    }

    public function test_the_mark_goes_once_the_lead_leaves_the_stage(): void
    {
        $lead = $this->chat('Perlu Resi');
        $this->inShop(fn () => $lead->forceFill(['stage_id' => $this->stageId(RetailStages::CANCELLED)])->save());

        $this->assertStringNotContainsString('wa-ok', $this->card(Livewire::test(Inbox::class)->html(), $lead));
    }
}
