<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\BusinessLocation;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Receipt printer profiles (ESC/POS).
 *
 * Rides {@see SimpleCrudController}; the whole area is gated by the single flat
 * permission `access_printers`, so {@see ability()} returns it for every action.
 * `printers.created_by` is NOT NULL — the base's default
 * {@see SimpleCrudController::fillableSystemColumns()} stamps it.
 */
class PrinterController extends SimpleCrudController
{
    protected string $model = Printer::class;

    protected string $viewPath = 'crud';

    protected string $routePrefix = 'printers';

    protected string $permission = 'access_printers';

    protected string $label = 'lang_v1.printer';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'connection_type' => 'lang_v1.connection_type',
        'ip_address' => 'lang_v1.ip_address',
        'port' => 'lang_v1.port',
    ];

    protected function ability(string $action): string
    {
        return $this->permission;
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'connection_type' => 'required|in:network,windows,linux',
            'capability_profile' => 'required|in:default,simple,SP2000,TEP-200M,P822D',
            'char_per_line' => 'nullable|integer|min:1|max:200',
            // A network printer needs an address; the browser/OS kinds do not.
            'ip_address' => 'nullable|required_if:connection_type,network|string|max:255',
            'port' => 'nullable|string|max:255',
            'path' => 'nullable|string|max:255',
        ];
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        return BusinessLocation::where('printer_id', $record->id)->exists()
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.business_locations')])
            : null;
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['fields' => [
            ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
            ['name' => 'connection_type', 'label' => __('lang_v1.connection_type'), 'type' => 'select',
             'required' => true, 'options' => [
                 'network' => __('lang_v1.network'),
                 'windows' => __('lang_v1.windows'),
                 'linux' => __('lang_v1.linux'),
             ]],
            ['name' => 'capability_profile', 'label' => __('lang_v1.capability_profile'), 'type' => 'select',
             'required' => true, 'options' => [
                 'default' => __('lang_v1.default'),
                 'simple' => __('lang_v1.simple'),
                 'SP2000' => 'SP2000',
                 'TEP-200M' => 'TEP-200M',
                 'P822D' => 'P822D',
             ]],
            ['name' => 'char_per_line', 'label' => __('lang_v1.char_per_line'), 'type' => 'number'],
            ['name' => 'ip_address', 'label' => __('lang_v1.ip_address'), 'type' => 'text',
             'hint' => __('lang_v1.printer_ip_hint')],
            ['name' => 'port', 'label' => __('lang_v1.port'), 'type' => 'text'],
        ]];
    }
}
