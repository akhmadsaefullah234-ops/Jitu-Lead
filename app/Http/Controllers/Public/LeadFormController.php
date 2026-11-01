<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\LandingPages\PageData;
use App\LandingPages\WhatsAppRotation;
use App\LeadForms\FormFields;
use App\LeadForms\FormView;
use App\LeadForms\SubmitLeadForm;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Forms built under "Formulir": the hosted page (/f/{agency}/{form}, also inside iframes), the older single-form
 * link (/f/{capture_token}, now the main form), the submission, and the round-robin WhatsApp hop of buttons.
 */
class LeadFormController extends Controller
{
    public function show(string $tenant, string $form, CurrentTenant $current): View
    {
        $tenant = $this->tenant($tenant);

        return $current->run($tenant, function () use ($tenant, $form) {
            $form = LeadForm::query()->where('slug', $form)->firstOrFail();
            abort_unless($form->isActive(), 404);
            $form->newQuery()->whereKey($form->getKey())->increment('views');

            return view('public.form', FormView::page($form, $tenant, preview: false));
        });
    }

    public function preview(string $tenant, string $form, CurrentTenant $current): View
    {
        $tenant = $this->tenant($tenant);

        return $current->run($tenant, fn () => view('public.form', FormView::page(LeadForm::query()->where('slug', $form)->firstOrFail(), $tenant, preview: true)));
    }

    /** The link agencies shared before forms could be built: it shows their main form. */
    public function legacy(string $token, CurrentTenant $current): View
    {
        $tenant = Tenant::query()->where('capture_token', $token)->where('status', '!=', 'suspended')->firstOrFail();

        return $current->run($tenant, fn () => view('public.form', FormView::page(LeadForm::primary(), $tenant, preview: false)));
    }

    public function submit(Request $request, string $tenant, string $form, CurrentTenant $current, SubmitLeadForm $submit): JsonResponse|Response|RedirectResponse
    {
        $tenant = $this->tenant($tenant);

        return $current->run($tenant, function () use ($request, $tenant, $form, $submit) {
            $form = LeadForm::query()->where('slug', $form)->firstOrFail();
            abort_unless($form->isActive(), 404);
            $fields = $form->fieldList();

            // Bots fill the hidden field; they get the usual thank-you and nothing is stored.
            if (filled($request->input('website'))) {
                return $this->respond($request, ['type' => 'message', 'message' => (string) ($form->after_submit['message'] ?? 'Terima kasih!')]);
            }

            try {
                $data = $request->validate([
                    ...FormFields::rules($fields),
                    'page' => ['nullable', 'integer'],
                    'event_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
                    'source_url' => ['nullable', 'url', 'max:500'],
                    ...array_fill_keys([...SubmitLeadForm::CAMPAIGN_FIELDS, ...SubmitLeadForm::BROWSER_IDS], ['nullable', 'string', 'max:200']),
                ], [], FormFields::attributes($fields));
            } catch (ValidationException $e) {
                if ($request->expectsJson()) {
                    throw $e;
                }

                return $this->page('Data belum lengkap', implode(' ', $e->validator->errors()->all()), 422);
            }

            // Uploaded files are not in validate()'s output for nested keys on every PHP setup; take them from the request.
            foreach ($fields as $f) {
                if ($f['type'] === 'photo' && $request->hasFile('f.'.$f['key'])) {
                    $data['f'][$f['key']] = $request->file('f.'.$f['key']);
                }
            }

            $result = $submit($tenant, $form, $data, $request);

            return $this->respond($request, $result['action']);
        });
    }

    /** "Agen bergiliran" WhatsApp buttons: the next agent's number, then on to WhatsApp. */
    public function whatsapp(Request $request, string $tenant, CurrentTenant $current): RedirectResponse
    {
        $tenant = $this->tenant($tenant);
        $number = $current->run($tenant, fn () => WhatsAppRotation::next($tenant));
        abort_if($number === '', 404);

        return redirect()->away(PageData::waLink($number, mb_substr((string) $request->query('text', ''), 0, 1000)));
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->where('status', '!=', 'suspended')->firstOrFail();
    }

    private function respond(Request $request, array $action): JsonResponse|Response|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'action' => $action]);
        }

        if (in_array($action['type'], ['redirect', 'whatsapp'], true) && filled($action['url'] ?? null)) {
            return redirect()->away($action['url']);
        }

        return $this->page('Terima kasih', (string) ($action['message'] ?? 'Terima kasih!'));
    }

    private function page(string $title, string $message, int $status = 200): Response
    {
        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'
            .'<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem"><h1>'.e($title).'</h1><p>'.e($message).'</p>'
            .'<p><a href="javascript:history.back()">Kembali</a></p></body>', $status);
    }
}
