<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Enums\Role;
use App\Filament\Concerns\InSettingsAndTools;
use App\Filament\Pages\AgencyProfilePage;
use App\Filament\Pages\AiHealthPage;
use App\Filament\Pages\AiModes;
use App\Filament\Pages\AiSurveyReport;
use App\Filament\Pages\CtwaReadinessPage;
use App\Filament\Pages\DailyReports;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DataPrivacy;
use App\Filament\Pages\FactReview;
use App\Filament\Pages\ImportLeads;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\Notifications;
use App\Filament\Pages\PaymentGatewayPage;
use App\Filament\Pages\PaymentSettingsPage;
use App\Filament\Pages\Pipeline;
use App\Filament\Pages\PropertySetup;
use App\Filament\Pages\Reports;
use App\Filament\Pages\SettingsAndTools;
use App\Filament\Pages\ShippingSettingsPage;
use App\Filament\Pages\StageAutomationPage;
use App\Filament\Pages\Subscription;
use App\Filament\Pages\Tasks;
use App\Filament\Pages\TrackingSettings;
use App\Filament\Pages\Tutorial;
use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\Filament\Resources\LeadForms\LeadFormResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Filament\Resources\LostReasons\LostReasonResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\MediaFolders\MediaFolderResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\Stages\StageResource;
use App\Filament\Resources\Team\TeamResource;
use App\Filament\Resources\WaChannels\WaChannelResource;
use App\Filament\Support\AgencyProfileTabs;
use App\Filament\Support\SettingsCatalog;
use App\Models\AiSuggestion;
use App\Models\Subscription as SubscriptionModel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The /app sidebar holds only the main menu; the settings pages and tools moved to "Pengaturan & Alat" (cards),
 * the agency's own settings to "Profil agensi" (tabs, from the account menu), Impor lead to a button on Lead, and
 * AI dan Survei to a tab on Laporan. Only the way they are opened changed: URLs, routes and access rules did not.
 */
class SettingsAndToolsTest extends TestCase
{
    use RefreshDatabase;

    /** The sidebar, in order. Each item shows only to those who may open it. */
    private const MAIN_MENU = [
        Dashboard::class, Pipeline::class, LeadResource::class, Inbox::class, ShipmentResource::class, Tasks::class,
        LandingPageResource::class, LeadFormResource::class, PropertyResource::class, Reports::class, Tutorial::class, SettingsAndTools::class,
    ];

    /** Every page and resource the sidebar listed before the change. */
    private const USED_TO_BE_IN_THE_MENU = [
        ...self::MAIN_MENU,
        AiModes::class, AiKnowledgeResource::class, AiSuggestionResource::class, PropertySetup::class, FactReview::class, AiHealthPage::class,
        StageResource::class, StageAutomationPage::class, FollowUpRuleResource::class, LeadSourceResource::class, LostReasonResource::class,
        WaChannelResource::class, TrackingSettings::class, CtwaReadinessPage::class,
        PaymentSettingsPage::class, PaymentGatewayPage::class, ShippingSettingsPage::class,
        MediaAssetResource::class, MediaFolderResource::class,
        AgencyProfilePage::class, TeamResource::class, Subscription::class, Notifications::class, DailyReports::class, DataPrivacy::class,
        ImportLeads::class, AiSurveyReport::class,
    ];

    private function agencyOf(string $industry, string $suffix = ''): Tenant
    {
        $slug = 'agensi-'.$industry.($suffix === '' ? '' : '-'.$suffix);

        return app(ProvisionTenant::class)(ucwords(str_replace('-', ' ', $slug)), $slug, User::factory()->create(), $industry);
    }

    private function userFor(Tenant $tenant, Role $role): User
    {
        return $role === Role::Admin ? $this->adminOf($tenant) : $this->member($tenant, $role);
    }

    private function base(Tenant $tenant): string
    {
        return '/app/'.$tenant->slug;
    }

    private static function url(string $class): string
    {
        return is_subclass_of($class, Resource::class) ? $class::getUrl('index') : $class::getUrl();
    }

    /** @return list<string> the sidebar labels of a page, in order */
    private function sidebar(TestResponse $response): array
    {
        preg_match_all('/x-bind:aria-label="\$store\.sidebar\.isOpen \? null : \'([^\']*)\'"/', $response->getContent(), $m);

        return array_map(fn (string $label) => html_entity_decode(json_decode('"'.$label.'"'), ENT_QUOTES), $m[1]);
    }

    public static function rolesAndIndustries(): array
    {
        $cases = [];

        foreach (['retail', 'properti', 'digital'] as $industry) {
            foreach ([Role::Admin, Role::Manager, Role::Agent] as $role) {
                $cases[$industry.' '.$role->value] = [$industry, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('rolesAndIndustries')]
    public function test_the_sidebar_holds_only_the_main_menu_and_the_cards_follow_access(string $industry, Role $role): void
    {
        $tenant = $this->agencyOf($industry);
        $this->actingInTenant($this->userFor($tenant, $role), $tenant);

        $expected = collect(self::MAIN_MENU)->filter(fn (string $class) => $class::canAccess())->map(fn (string $class) => $class::getNavigationLabel())->values()->all();
        $dashboard = $this->get($this->base($tenant))->assertOk();

        $this->assertSame($expected, $this->sidebar($dashboard));
        $this->assertDoesNotMatchRegularExpression('/data-group-label="[^"]+"/', $dashboard->getContent(), 'no group that opens and closes');
        $this->assertSame(['retail' => 'Produk', 'properti' => 'Properti', 'digital' => 'Produk digital'][$industry], PropertyResource::getNavigationLabel());

        $page = $this->get(SettingsAndTools::getUrl());
        $cards = SettingsCatalog::cards();
        $this->assertSame($cards !== [], SettingsAndTools::canAccess());

        if ($cards === []) {
            $page->assertForbidden();
            $this->assertNotContains(SettingsAndTools::LABEL, $this->sidebar($dashboard));
        } else {
            $page->assertOk();
        }

        foreach (SettingsCatalog::members() as $class) {
            $url = self::url($class);

            if ($class::canAccess()) {
                $page->assertSee('href="'.$url.'"', false);
                $this->get($url)->assertOk();
            } else {
                $page->assertDontSee('href="'.$url.'"', false);
                $this->assertContains($this->get($url)->getStatusCode(), [403, 404], $class.' is still refused by URL');
            }
        }

        foreach (SettingsCatalog::groups() as $group) {
            $this->assertNotEmpty($group['cards']);
            $page->assertSee($group['label']);
        }
    }

    public function test_what_each_role_and_industry_finds_on_pengaturan_dan_alat(): void
    {
        $labels = function (string $industry, Role $role): array {
            $tenant = $this->agencyOf($industry, $role->value);
            $this->actingInTenant($this->userFor($tenant, $role), $tenant);

            return collect(SettingsCatalog::cards())->pluck('label')->all();
        };

        $shopAdmin = $labels('retail', Role::Admin);
        $this->assertContains('Pengaturan pengiriman', $shopAdmin);
        $this->assertContains('Otomatisasi tahap', $shopAdmin);
        $this->assertContains('Mode AI', $shopAdmin);
        $this->assertNotContains('Isi properti', $shopAdmin);
        $this->assertNotContains('Siap iklan CTWA', $shopAdmin);
        $this->assertNotContains('Pembayaran otomatis', $shopAdmin);

        $agencyAdmin = $labels('properti', Role::Admin);
        $this->assertContains('Isi properti', $agencyAdmin);
        $this->assertContains('Tinjau data AI', $agencyAdmin);
        $this->assertContains('Kesehatan AI', $agencyAdmin);
        $this->assertContains('Siap iklan CTWA', $agencyAdmin);
        $this->assertNotContains('Pengaturan pengiriman', $agencyAdmin);

        $this->assertContains('Pembayaran otomatis', $labels('digital', Role::Admin));

        foreach ([Role::Manager, Role::Agent] as $role) {
            $cards = $labels('retail', $role);
            $this->assertNotContains('Mode AI', $cards, $role->value);
            $this->assertNotContains('Koneksi WhatsApp', $cards, $role->value);
            $this->assertNotContains('Pembayaran', $cards, $role->value);
        }
    }

    #[DataProvider('rolesAndIndustries')]
    public function test_every_page_that_was_in_the_menu_can_still_be_reached(string $industry, Role $role): void
    {
        $tenant = $this->agencyOf($industry);
        $this->actingInTenant($this->userFor($tenant, $role), $tenant);

        // Where a link may be: the sidebar and the account menu (on the Dashboard), the cards, the Profil agensi tabs,
        // the buttons on the Lead list, and the tabs on Laporan.
        $html = $this->get($this->base($tenant))->getContent();

        foreach ([SettingsAndTools::class, LeadResource::class, Reports::class, ...AgencyProfileTabs::members()] as $place) {
            if ($place::canAccess()) {
                $html .= $this->get(self::url($place))->getContent();
            }
        }

        foreach (self::USED_TO_BE_IN_THE_MENU as $class) {
            if ($class::canAccess()) {
                $this->assertStringContainsString('href="'.self::url($class).'"', $html, $class);
            }
        }
    }

    public function test_an_open_settings_page_keeps_the_menu_item_lit_and_links_back(): void
    {
        $tenant = $this->agencyOf('retail');
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        foreach ([AiModes::class, ShippingSettingsPage::class, WaChannelResource::class, MediaAssetResource::class, FollowUpRuleResource::class] as $class) {
            $response = $this->get(self::url($class))->assertOk();
            $this->assertMatchesRegularExpression('/<li\s+class="fi-sidebar-item fi-active[^"]*"\s*>\s*<a\s+href="'.preg_quote(SettingsAndTools::getUrl(), '/').'"/', $response->getContent(), $class);
            $response->assertSeeInOrder(['fi-breadcrumbs', 'href="'.SettingsAndTools::getUrl().'"', 'Pengaturan &amp; Alat'], false);
        }

        $this->get(WaChannelResource::getUrl('create'))->assertOk()->assertSeeInOrder(['fi-breadcrumbs', 'href="'.SettingsAndTools::getUrl().'"'], false);

        // Every page of every resource on the page has the breadcrumb.
        foreach (SettingsCatalog::members() as $class) {
            $pages = is_subclass_of($class, Resource::class) ? array_map(fn ($registration) => $registration->getPage(), $class::getPages()) : [$class];

            foreach ($pages as $page) {
                $this->assertContains(InSettingsAndTools::class, class_uses_recursive($page), $page);
            }
        }
    }

    public function test_the_sidebar_shows_the_sum_of_the_card_badges(): void
    {
        $tenant = $this->agencyOf('retail');
        $this->actingInTenant($this->adminOf($tenant), $tenant);
        $this->assertNull(SettingsAndTools::getNavigationBadge());

        app(CurrentTenant::class)->run($tenant, function () {
            AiSuggestion::create(['question' => 'Ada promo?', 'answer' => 'Ada.', 'status' => 'pending']);
            AiSuggestion::create(['question' => 'Bisa COD?', 'answer' => 'Bisa.', 'status' => 'pending']);
        });

        $dashboard = $this->get($this->base($tenant))->assertOk();
        $this->assertMatchesRegularExpression('/href="'.preg_quote(SettingsAndTools::getUrl(), '/').'".*?fi-badge.*?>\s*2\s*</s', $dashboard->getContent());
        $this->get(SettingsAndTools::getUrl())->assertSee('<span class="st-badge" aria-label="2 perlu ditinjau">2</span>', false);

        // A team leader may not open Usulan Q&A, so it is not counted for them.
        $this->actingInTenant($this->member($tenant, Role::Manager), $tenant);
        $this->assertNull(SettingsAndTools::getNavigationBadge());

        // Profil agensi's pages are not cards, so their badges are never added here.
        $this->assertSame([], array_intersect(SettingsCatalog::members(), AgencyProfileTabs::members()));
    }

    public function test_the_search_box_finds_cards_by_name_and_keywords(): void
    {
        $tenant = $this->agencyOf('retail');
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->assertContains('Pengaturan pengiriman', SettingsCatalog::search('ongkir'));
        $this->assertContains('Pembayaran', SettingsCatalog::search('rekening'));
        $this->assertContains('Pelacakan iklan', SettingsCatalog::search('pixel'));
        $this->assertSame(['Otomatisasi tahap', 'Follow-up otomatis'], SettingsCatalog::search('follow up'));
        $this->assertSame(SettingsCatalog::search('follow up'), SettingsCatalog::search('Follow-Up'));
        $this->assertSame(['Mode AI'], SettingsCatalog::search('mode ai'));
        $this->assertSame([], SettingsCatalog::search('kata yang tidak ada'));
        $this->assertCount(count(SettingsCatalog::cards()), SettingsCatalog::search(''));

        $this->get(SettingsAndTools::getUrl())->assertOk()
            ->assertSee('Cari pengaturan')->assertSee('Tidak ada pengaturan yang cocok')
            ->assertSee('data-search="'.SettingsCatalog::cards()[0]['search'].'"', false)
            ->assertSeeInOrder(['Akses cepat', 'Koneksi WhatsApp', 'Mode AI', 'Pustaka media', 'AI Asisten'], false);
    }

    public function test_profil_agensi_opens_from_the_account_menu_with_tabs(): void
    {
        $tenant = $this->agencyOf('retail');
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->get($this->base($tenant))->assertOk()->assertSee('Profil agensi')->assertSee('href="'.AgencyProfilePage::getUrl().'"', false);

        foreach (AgencyProfileTabs::members() as $class) {
            $this->get(self::url($class))->assertOk()
                ->assertSeeInOrder(['fi-page-sub-navigation-tabs', 'Profil', 'Tim', 'Langganan', 'Notifikasi', 'Laporan harian', 'Data &amp; privasi'], false)
                ->assertSee('Profil saya');
        }

        // The tab of the page being shown is lit, also on Tim (whose add and edit are on the same page).
        $this->assertMatchesRegularExpression('/fi-active[^>]*>[\s\S]{0,600}?href="'.preg_quote(TeamResource::getUrl('index'), '/').'"|href="'.preg_quote(TeamResource::getUrl('index'), '/').'"[^>]*fi-active/',
            $this->get(TeamResource::getUrl('index'))->getContent());

        // An agent gets only the tabs it may open, and the item opens the first of them.
        $this->actingInTenant($this->member($tenant, Role::Agent), $tenant);
        $this->assertSame(['Notifikasi', 'Laporan harian'], array_values(AgencyProfileTabs::visible()));
        $this->assertSame(Notifications::getUrl(), AgencyProfileTabs::firstUrl());
        $this->get($this->base($tenant))->assertSee('href="'.Notifications::getUrl().'"', false);
        $this->get(Notifications::getUrl())->assertOk()->assertDontSee('href="'.Subscription::getUrl().'"', false)->assertDontSee('href="'.AgencyProfilePage::getUrl().'"', false);
        $this->get(AgencyProfilePage::getUrl())->assertForbidden();
        $this->get(Subscription::getUrl())->assertForbidden();
        $this->get(TeamResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_locked_trial_keeps_its_one_menu_item_and_no_profile_tabs(): void
    {
        $tenant = $this->agencyOf('retail');
        $tenant->currentSubscription()->update(['status' => SubscriptionModel::TRIAL, 'trial_ends_at' => now()->subDay(), 'current_period_end' => null]);
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $page = $this->get(Subscription::getUrl())->assertOk();
        $this->assertSame(['Langganan'], $this->sidebar($page));
        $page->assertDontSee('fi-page-sub-navigation-tabs', false)->assertDontSee('Profil agensi');
        $this->get(SettingsAndTools::getUrl())->assertRedirect(Subscription::getUrl());
    }

    public function test_impor_lead_is_a_button_on_lead_and_ai_dan_survei_a_tab_on_laporan(): void
    {
        $tenant = $this->agencyOf('properti');
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        $this->get(LeadResource::getUrl('index'))->assertOk()->assertSee('Impor lead')->assertSee('href="'.ImportLeads::getUrl().'"', false);
        $import = $this->get(ImportLeads::getUrl())->assertOk()->assertSee('href="'.LeadResource::getUrl('index').'"', false);
        $this->assertMatchesRegularExpression('/<li\s+class="fi-sidebar-item fi-active[^"]*"\s*>\s*<a\s+href="'.preg_quote(LeadResource::getUrl('index'), '/').'"/', $import->getContent());

        $this->get(Reports::getUrl())->assertOk()->assertSee('fi-page-sub-navigation-tabs', false)->assertSee('href="'.AiSurveyReport::getUrl().'"', false);
        $survey = $this->get(AiSurveyReport::getUrl())->assertOk()->assertSee('href="'.Reports::getUrl().'"', false);
        $this->assertMatchesRegularExpression('/<li\s+class="fi-sidebar-item fi-active[^"]*"\s*>\s*<a\s+href="'.preg_quote(Reports::getUrl(), '/').'"/', $survey->getContent());

        // Without access, neither shows: an agent cannot open AI dan Survei; a shop has no such report.
        $this->actingInTenant($this->member($tenant, Role::Agent), $tenant);
        $this->get(Reports::getUrl())->assertOk()->assertDontSee('fi-page-sub-navigation-tabs', false)->assertDontSee('href="'.AiSurveyReport::getUrl().'"', false);
        $this->get(AiSurveyReport::getUrl())->assertForbidden();
    }
}
