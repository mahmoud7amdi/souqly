<?php

namespace App\Http\Controllers;

use App\Models\NotificationTemplate;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification templates — the email / SMS / WhatsApp text sent for each event.
 *
 * Deliberately *not* a {@see Concerns\SimpleCrudController}. The set of templates
 * is fixed: {@see NotificationTemplate::templateTypes()} names all sixteen, and a
 * tenant edits the sixteen rather than creating and deleting rows. So there is no
 * create and no destroy — only a list, a per-type form, and an upsert.
 *
 * That upsert matters. `notification_templates` has no unique index on
 * (business_id, template_for), and a tenant who has never opened this screen has
 * no row at all, so `update()` has to create-or-update rather than assume one
 * exists — {@see firstOrNew()} keyed on the type, inside a transaction.
 *
 * Gated by the flat `business_settings.access`.
 */
class NotificationTemplateController extends Controller
{
    /**
     * Placeholders the sender substitutes at send time.
     *
     * Listed on the form because a template with a mistyped tag fails silently —
     * the literal `{invoce_number}` is simply printed to the customer. The
     * consuming side lands with the notification commands in Item 12.
     *
     * @return array<int, string>
     */
    public static function availableTags(): array
    {
        return [
            '{business_name}', '{location_name}', '{location_address}',
            '{contact_name}', '{contact_mobile}', '{contact_email}',
            '{invoice_number}', '{invoice_url}', '{invoice_date}',
            '{total_amount}', '{paid_amount}', '{due_amount}',
            '{received_amount}', '{payment_method}',
        ];
    }

    public function index()
    {
        $this->permit('business_settings.access');

        // One query, keyed by type, so the list can say which of the sixteen are
        // configured without sixteen lookups.
        $configured = NotificationTemplate::query()->get()->keyBy('template_for');

        $templates = collect(NotificationTemplate::templateTypes())->map(fn (string $type) => [
            'type' => $type,
            'label' => __('lang_v1.notification_'.$type),
            'record' => $configured->get($type),
        ]);

        return view('notification_template.index', [
            'templates' => $templates,
            'canUpdate' => $this->allows('business_settings.access'),
        ]);
    }

    public function edit(string $templateFor)
    {
        $this->permit('business_settings.access');

        abort_unless(in_array($templateFor, NotificationTemplate::templateTypes(), true), 404);

        $record = NotificationTemplate::where('template_for', $templateFor)->first();

        return view('notification_template.edit', [
            'templateFor' => $templateFor,
            'label' => __('lang_v1.notification_'.$templateFor),
            'record' => $record,
            'tags' => static::availableTags(),
        ]);
    }

    public function update(Request $request, string $templateFor)
    {
        $this->permit('business_settings.access');

        abort_unless(in_array($templateFor, NotificationTemplate::templateTypes(), true), 404);

        $validated = $request->validate([
            'subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string|max:5000',
            'sms_body' => 'nullable|string|max:1000',
            'whatsapp_text' => 'nullable|string|max:1000',
            // A comma-separated list, not a single address, so it is validated as
            // a string here and split by the sender.
            'cc' => 'nullable|string|max:255',
            'bcc' => 'nullable|string|max:255',
        ]);

        $validated['auto_send'] = $request->boolean('auto_send');
        $validated['auto_send_sms'] = $request->boolean('auto_send_sms');
        $validated['auto_send_wa_notif'] = $request->boolean('auto_send_wa_notif');

        try {
            DB::transaction(function () use ($templateFor, $validated) {
                // No unique index on (business_id, template_for), and a tenant who
                // has never opened this screen has no row — so create-or-update.
                $record = NotificationTemplate::firstOrNew([
                    'template_for' => $templateFor,
                    'business_id' => Tenancy::id(),
                ]);

                $record->fill($validated)->save();
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex('notification-templates.index', $output);
    }
}
