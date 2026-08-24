{{--
    Everything below the totals: payments, the QR data block, notes, the barcode
    and the footer. Shared by both designs, because these are facts about the
    document rather than choices about its look — a business that switches from
    classic to elegant does not expect its payment history to disappear.
--}}

@if (! empty($paymentLines))
    <div style="margin-top:16px">
        <div class="block-label">{{ __('lang_v1.payments') }}</div>
        <table class="items" style="margin-top:4px">
            <thead>
                <tr>
                    <th class="start" style="border-bottom:1px solid #dee6e3">{{ __('lang_v1.date') }}</th>
                    <th class="start" style="border-bottom:1px solid #dee6e3">{{ __('lang_v1.payment_method') }}</th>
                    <th class="num" style="border-bottom:1px solid #dee6e3">{{ __('lang_v1.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($paymentLines as $payment)
                    <tr>
                        <td style="border-bottom:1px solid #eef2f1">{{ $payment['date'] }}</td>
                        <td style="border-bottom:1px solid #eef2f1">{{ $payment['method'] }}</td>
                        <td class="num" style="border-bottom:1px solid #eef2f1">{{ $payment['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (! empty($qrFields))
    {{-- The layout's QR fields, printed as labelled text rather than as a code.
         Which e-invoicing standard applies here is an open question — Egypt's ETA
         and Saudi ZATCA encode different payloads — and a QR that scans to the
         wrong schema is worse than no QR at all, because it looks authoritative.
         The data is what the tenant asked to publish; the machine-readable form
         waits on the decision. Recorded in NOTES §15. --}}
    <div style="margin-top:16px; padding:8px 10px; background:#f8fafa; border:1px solid #e2e8e6">
        @foreach ($qrFields as $field)
            <span class="tiny" style="display:inline-block; margin-inline-end:14px">
                <span class="muted">{{ $field['label'] }}:</span> <span class="strong">{{ $field['value'] }}</span>
            </span>
        @endforeach
    </div>
@endif

@if (filled($notes))
    <div class="notes">
        <div class="block-label">{{ __('lang_v1.notes') }}</div>
        <div class="small">{!! nl2br(e($notes)) !!}</div>
    </div>
@endif

@if ($barcode)
    <div class="center" style="margin-top:16px">
        <img src="{{ $barcode }}" alt="{{ $document->invoice_no }}" class="barcode">
    </div>
@endif

@if (filled($footer))
    {{-- The footer is the tenant's own text — terms, a return policy, a thank
         you. Escaped and line-broken, never raw: it is a settings field, and a
         settings field that renders as markup is a settings field that can carry
         a script into every invoice the business sends out. --}}
    <hr class="hair" style="margin-top:18px">
    <div class="tiny muted center" style="margin-top:8px">{!! nl2br(e($footer)) !!}</div>
@endif
