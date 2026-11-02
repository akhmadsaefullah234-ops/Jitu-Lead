<?php

namespace App\Filament\Resources\LandingPages\Pages;

use App\Billing\Deny;
use App\Billing\PlanLimits;
use App\Filament\Resources\LandingPages\LandingPageResource;
use App\LandingPages\Navigation;
use App\LandingPages\Sections;
use App\LandingPages\Templates;
use App\LeadForms\FormTemplates;
use App\Models\LeadForm;
use App\Support\Industry;
use Filament\Resources\Pages\CreateRecord;

class CreateLandingPage extends CreateRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function beforeCreate(): void
    {
        if ($denied = PlanLimits::current()?->denyAdding('landing_pages')) {
            Deny::notify($denied);
            $this->halt();
        }
    }

    /** The chosen template fills in the sections, colour and font; the editor opens next. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return static::fromTemplate($data);
    }

    /**
     * A new page from a template. A shop's product template can take its product from the catalog and gets an order
     * form: a chosen one, or a new "Order produk" form made for this product. Must run inside the tenant.
     *
     * @param  array{template?: string, title: string, slug: string, product_id?: mixed, order_form?: mixed}  $data
     * @return array<string, mixed>
     */
    public static function fromTemplate(array $data): array
    {
        $key = array_key_exists($data['template'] ?? '', Templates::options(Industry::current()->key)) ? $data['template'] : Templates::BLANK;
        $template = Templates::get($key);
        $blocks = Templates::blocks($key);
        $propertyId = null;

        if (Templates::sellsProduct($key)) {
            $catalog = Sections::fromCatalog($data['product_id'] ?? null);
            $propertyId = $catalog ? (int) $data['product_id'] : null;

            foreach ($blocks as $i => $block) {
                if ($catalog && $block['type'] === 'product') {
                    $blocks[$i]['props'] = array_merge($block['props'], ['name' => $catalog['name'], 'property_id' => $propertyId],
                        $catalog['price'] !== null ? ['price' => $catalog['price'], 'promo_price' => null] : [],
                        $catalog['variants'] !== [] ? ['variants' => array_map(fn ($v) => ['name' => $v, 'swatch' => null, 'images' => []], $catalog['variants'])] : [],
                        $catalog['sizes'] !== [] ? ['sizes' => $catalog['sizes']] : []);
                }

                if ($catalog && $catalog['specs'] !== [] && $block['type'] === 'specs') {
                    $blocks[$i]['props']['rows'] = $catalog['specs'];
                }
            }

            $product = collect($blocks)->firstWhere('type', 'product')['props'] ?? [];
            $choice = $data['order_form'] ?? 'new';
            $formId = is_numeric($choice) ? LeadForm::query()->whereKey((int) $choice)->value('id') : null;
            $formId ??= FormTemplates::createOrderForm($catalog['name'] ?? '', array_column((array) ($product['variants'] ?? []), 'name'))->getKey();

            foreach ($blocks as $i => $block) {
                if ($block['type'] === 'form') {
                    $blocks[$i]['props']['form_id'] = $formId;
                }
            }
        }

        return [
            'title' => $data['title'], 'slug' => $data['slug'], 'template' => $key, 'status' => 'draft',
            'color' => $template['color'], 'font' => $template['font'], 'description' => $template['meta'] ?: null,
            'blocks' => $blocks, 'property_id' => $propertyId,
            'page_events' => $template['page_events'] ?? null,
            'navigation' => Navigation::of($template['navigation'] ?? []),
        ];
    }

    protected function afterCreate(): void
    {
        EditLandingPage::ensureCaptureToken();
    }
}
