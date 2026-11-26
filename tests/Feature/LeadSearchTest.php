<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\Role;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\Pipeline;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Models\CarrierAccount;
use App\Models\Lead;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Shipping\Carriers\ShipmentStatus;
use App\Support\CurrentTenant;
use App\Support\LeadSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phones are stored as +62..., buyers and CS type 0812..., 62812..., "+62 812-..." or the last digits. One search
 * (App\Support\LeadSearch) for Pipeline, the Lead list, global search, Pengiriman and the Inbox.
 */
class LeadSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private int $n = 0;

    /** Every way a buyer or CS types the number of Budi (+6281234567890). */
    public const BUDI_TYPED = ['081234567890', '0812-3456-7890', '0812 3456 7890', '6281234567890', '+62 812-3456-7890', '(0812) 3456 7890', '7890', '0812345'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $this->inShop(fn () => CarrierAccount::query()->create(['provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'k', 'client_secret' => 's'], 'settings' => []]));
        $this->actingInTenant($this->adminOf($this->shop), $this->shop);
    }

    public function test_phone_patterns(): void
    {
        $this->assertSame(['%081234%', '%6281234%'], LeadSearch::phonePatterns('0812-34'));
        $this->assertSame(['%62812%'], LeadSearch::phonePatterns('+62 812'));
        $this->assertSame(['%7890%'], LeadSearch::phonePatterns(' 7890 '));
        $this->assertSame([], LeadSearch::phonePatterns('zzzzqqq'), 'A name is no phone search.');
        $this->assertSame([], LeadSearch::phonePatterns('Budi 0812'), 'Letters: a name, not a number.');
        $this->assertSame([], LeadSearch::phonePatterns('08'), 'Fewer than 3 digits.');
        $this->assertSame([], LeadSearch::phonePatterns('- ( ) +'));
        $this->assertSame([], LeadSearch::phonePatterns(''));
    }

    public function test_pipeline_finds_a_lead_by_phone_typed_any_way_and_by_name(): void
    {
        [$budi, $sari] = $this->twoLeads();

        foreach (self::BUDI_TYPED as $typed) {
            Livewire::test(Pipeline::class)->set('search', $typed)->assertSee('Budi Santoso')->assertDontSee('Sari Wulandari');
        }

        foreach (['budi', 'BUDI SANTOSO', 'Santoso'] as $typed) {
            Livewire::test(Pipeline::class)->set('search', $typed)->assertSee('Budi Santoso')->assertDontSee('Sari Wulandari');
        }

        Livewire::test(Pipeline::class)->set('search', 'zzzzqqq')->assertDontSee('Budi Santoso')->assertDontSee('Sari Wulandari');
        Livewire::test(Pipeline::class)->set('search', '%')->assertDontSee('Budi Santoso')->assertDontSee('Sari Wulandari');
    }

    public function test_lead_list_finds_a_lead_by_phone_typed_any_way_and_by_name(): void
    {
        [$budi, $sari] = $this->twoLeads();

        foreach ([...self::BUDI_TYPED, 'budi', 'BUDI SANTOSO', 'budi@contoh.id'] as $typed) {
            Livewire::test(ListLeads::class)->searchTable($typed)->assertCanSeeTableRecords([$budi])->assertCanNotSeeTableRecords([$sari]);
        }

        Livewire::test(ListLeads::class)->searchTable('zzzzqqq')->assertCountTableRecords(0);
        Livewire::test(ListLeads::class)->searchTable('')->assertCanSeeTableRecords([$budi, $sari]);
    }

    public function test_global_search_finds_a_lead_by_phone_typed_any_way_and_by_name(): void
    {
        [$budi] = $this->twoLeads();

        foreach ([...self::BUDI_TYPED, 'budi', 'BUDI SANTOSO'] as $typed) {
            $this->assertSame(['Budi Santoso'], LeadResource::getGlobalSearchResults($typed)->pluck('title')->map(fn ($t) => (string) $t)->all(), $typed);
        }

        $this->assertCount(0, LeadResource::getGlobalSearchResults('zzzzqqq'));
    }

    public function test_shipments_find_the_buyer_by_phone_typed_any_way_and_by_name(): void
    {
        [$budi, $sari] = $this->twoLeads();
        $budiParcel = $this->parcel($budi);
        $sariParcel = $this->parcel($sari);

        foreach ([...self::BUDI_TYPED, 'budi santoso'] as $typed) {
            Livewire::test(ListShipments::class)->set('activeTab', 'unprinted')->searchTable($typed)
                ->assertCanSeeTableRecords([$budiParcel])->assertCanNotSeeTableRecords([$sariParcel]);
        }

        Livewire::test(ListShipments::class)->set('activeTab', 'unprinted')->searchTable('zzzzqqq')->assertCountTableRecords(0);
        Livewire::test(ListShipments::class)->set('activeTab', 'unprinted')->searchTable($budiParcel->awb)->assertCanSeeTableRecords([$budiParcel])->assertCanNotSeeTableRecords([$sariParcel]);
    }

    public function test_inbox_search_finds_old_chats_beyond_the_newest_100(): void
    {
        [$budi, $sari] = $this->twoLeads();
        $this->chat($budi, '-30 days');
        $this->chat($sari, '-29 days');

        foreach (range(1, 101) as $i) {
            $this->chat($this->lead('Pembeli Baru '.$i, '0856'.str_pad((string) $i, 8, '0', STR_PAD_LEFT)), '-'.$i.' minutes');
        }

        Livewire::test(Inbox::class)->assertDontSee('Budi Santoso');

        foreach ([...self::BUDI_TYPED, 'budi'] as $typed) {
            Livewire::test(Inbox::class)->set('search', $typed)->assertSee('Budi Santoso')->assertDontSee('Sari Wulandari')->assertDontSee('Pembeli Baru 1');
        }

        Livewire::test(Inbox::class)->set('search', 'zzzzqqq')->assertSee('Tidak ditemukan')->assertDontSee('Budi Santoso');
        Livewire::test(Inbox::class)->assertSee('Cari nama atau nomor');
    }

    public function test_inbox_search_ignores_the_closing_filter(): void
    {
        [$budi, $sari] = $this->twoLeads();
        $this->chat($budi);
        $this->chat($sari);

        // No closing waits, so "Closing" falls back to all; a search never narrows to closings either.
        Livewire::test(Inbox::class)->set('filter', 'closing')->set('search', 'Sari')
            ->assertViewHas('activeFilter', 'all')->assertSee('Sari Wulandari')->assertDontSee('Budi Santoso');
    }

    public function test_agents_only_find_their_own_leads(): void
    {
        [$budi, $sari] = $this->twoLeads();
        $agent = $this->member($this->shop, Role::Agent);
        $sari->forceFill(['owner_id' => $agent->getKey()])->save();
        $this->chat($budi);
        $this->chat($sari);
        $this->actingInTenant($agent, $this->shop);

        Livewire::test(Pipeline::class)->set('search', '0812')->assertSee('Sari Wulandari')->assertDontSee('Budi Santoso');
        Livewire::test(ListLeads::class)->searchTable('081234567890')->assertCountTableRecords(0);
        Livewire::test(ListLeads::class)->searchTable('0812')->assertCanSeeTableRecords([$sari])->assertCanNotSeeTableRecords([$budi]);
        $this->assertCount(0, LeadResource::getGlobalSearchResults('081234567890'));
        Livewire::test(Inbox::class)->set('search', '081234567890')->assertSee('Tidak ditemukan')->assertDontSee('Budi Santoso');
        Livewire::test(Inbox::class)->set('search', 'sari')->assertSee('Sari Wulandari');
    }

    public function test_another_tenant_with_the_same_number_stays_out(): void
    {
        $this->twoLeads();
        $other = $this->makeTenant('agensi-lain');
        app(CurrentTenant::class)->run($other, fn () => Lead::create([
            'name' => 'Budi Tenant Lain', 'phone' => '081234567890', 'stage_id' => $this->stage($other, 'Lead baru')->getKey(), 'owner_id' => $this->adminOf($other)->getKey(),
        ]));

        Livewire::test(Pipeline::class)->set('search', '081234567890')->assertSee('Budi Santoso')->assertDontSee('Budi Tenant Lain');
        Livewire::test(ListLeads::class)->searchTable('081234567890')->assertCountTableRecords(1);
        $this->assertSame(['Budi Santoso'], LeadResource::getGlobalSearchResults('Budi')->pluck('title')->map(fn ($t) => (string) $t)->all());
    }

    /** @return array{Lead, Lead} */
    private function twoLeads(): array
    {
        return [
            $this->lead('Budi Santoso', '081234567890', ['email' => 'budi@contoh.id']),
            $this->lead('Sari Wulandari', '+62 812-9876-5432'),
        ];
    }

    private function lead(string $name, string $phone, array $extra = []): Lead
    {
        $lead = $this->inShop(fn () => Lead::create($extra + [
            'name' => $name, 'phone' => $phone, 'stage_id' => (new RetailStages)->stage(RetailStages::SHIPPING)->getKey(), 'stage_entered_at' => now()->subHours(3),
            'owner_id' => $this->adminOf($this->shop)->getKey(), 'payment_type' => 'cod',
        ]));
        $this->assertStringStartsWith('+62', $lead->phone, 'Stored as +62..., as in production.');

        return $lead;
    }

    private function chat(Lead $lead, string $at = '-1 hour'): WaConversation
    {
        return $this->inShop(fn () => WaConversation::query()->create(['lead_id' => $lead->id, 'phone' => $lead->phone, 'last_inbound_at' => now()->modify($at), 'last_message_at' => now()->modify($at)]));
    }

    private function parcel(Lead $lead): Shipment
    {
        return $this->inShop(fn () => Shipment::query()->create([
            'lead_id' => $lead->getKey(), 'provider' => 'everpro', 'client_order_no' => 'JL-T-'.++$this->n, 'state' => Shipment::SHIPPED,
            'awb' => 'JNE00'.$this->n.'1234', 'courier' => 'JNE REG', 'status' => ShipmentStatus::AWB_CREATED, 'label_path' => 'labels/x.pdf',
        ]));
    }

    private function inShop(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->shop, $fn);
    }
}
