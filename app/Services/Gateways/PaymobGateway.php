<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paymob (Accept) — Egyptian gateway covering Visa/Mastercard/Meeza cards and
 * mobile wallets (Vodafone Cash, Orange Money, Etisalat Cash, we pay).
 *
 * Three-step flow, per Paymob's Accept API:
 *   1. POST /auth/tokens          → auth token
 *   2. POST /ecommerce/orders     → order id
 *   3. POST /acceptance/payment_keys → payment key, used to build the
 *      iframe/redirect URL the customer completes payment in.
 *
 * Callbacks are authenticated with an HMAC-SHA512 over a fixed, ordered
 * concatenation of transaction fields. We refuse any callback whose HMAC does
 * not match, so a forged "success" cannot settle an invoice.
 *
 * Amounts are transmitted in the minor unit (piastres) as integers.
 */
class PaymobGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'paymob';
    }

    public function label(): string
    {
        return __('lang_v1.pay_with_card_or_wallet');
    }

    public function isConfigured(): bool
    {
        return ! empty(config('paymob.api_key'))
            && ! empty(config('paymob.integration_id'))
            && ! empty(config('paymob.iframe_id'))
            && ! empty(config('paymob.hmac_secret'));
    }

    /**
     * @param  array<string, mixed>  $payer
     * @return array{iframe_url: string, reference: string, raw: array<string, mixed>}
     */
    public function initiate(Transaction $transaction, float $amount, array $payer = []): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(__('lang_v1.payment_gateway_not_configured'));
        }

        $token = $this->authToken();
        $order = $this->createOrder($token, $transaction, $amount);
        $paymentKey = $this->paymentKey($token, $order['id'], $amount, $payer);

        return [
            'iframe_url' => sprintf(
                '%s/acceptance/iframes/%s?payment_token=%s',
                rtrim(config('paymob.base_url'), '/'),
                config('paymob.iframe_id'),
                $paymentKey
            ),
            'reference' => (string) $order['id'],
            'raw' => ['order' => $order],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verified: bool, success: bool, reference: string|null, amount: float|null, order_reference: string|null}
     */
    public function verifyCallback(array $payload): array
    {
        $failed = [
            'verified' => false,
            'success' => false,
            'reference' => null,
            'amount' => null,
            'order_reference' => null,
        ];

        // Paymob posts either {obj: {...}} (transaction callback) or a flat
        // query string (redirect callback). Normalise both.
        $data = $payload['obj'] ?? $payload;

        $received = $payload['hmac'] ?? ($data['hmac'] ?? null);

        if (empty($received) || ! is_array($data)) {
            Log::warning('Paymob callback rejected: no HMAC present.');

            return $failed;
        }

        if (! hash_equals($this->expectedHmac($data), (string) $received)) {
            Log::warning('Paymob callback rejected: HMAC mismatch.', [
                'order' => data_get($data, 'order.id') ?? ($data['order'] ?? null),
            ]);

            return $failed;
        }

        $success = filter_var(
            data_get($data, 'success', false),
            FILTER_VALIDATE_BOOLEAN
        ) && ! filter_var(data_get($data, 'is_voided', false), FILTER_VALIDATE_BOOLEAN)
            && ! filter_var(data_get($data, 'is_refunded', false), FILTER_VALIDATE_BOOLEAN);

        $amountCents = (int) data_get($data, 'amount_cents', 0);

        return [
            'verified' => true,
            'success' => $success,
            'reference' => (string) data_get($data, 'id'),
            'amount' => round($amountCents / 100, 2),
            'order_reference' => (string) (data_get($data, 'order.id') ?? data_get($data, 'order')),
        ];
    }

    /* ====================================================================
     | Paymob API steps
     ==================================================================== */

    protected function authToken(): string
    {
        $response = Http::asJson()
            ->timeout(30)
            ->post($this->url('/auth/tokens'), [
                'api_key' => config('paymob.api_key'),
            ]);

        $this->assertOk($response, 'auth');

        return (string) $response->json('token');
    }

    /**
     * @return array<string, mixed>
     */
    protected function createOrder(string $token, Transaction $transaction, float $amount): array
    {
        $response = Http::asJson()
            ->timeout(30)
            ->post($this->url('/ecommerce/orders'), [
                'auth_token' => $token,
                'delivery_needed' => false,
                'amount_cents' => $this->toMinorUnit($amount),
                'currency' => config('paymob.currency', 'EGP'),
                // Lets us tie a callback back to the invoice, and makes
                // Paymob reject a duplicate order for the same invoice.
                'merchant_order_id' => $transaction->id.'-'.now()->timestamp,
                'items' => [],
            ]);

        $this->assertOk($response, 'order');

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payer
     */
    protected function paymentKey(string $token, int $orderId, float $amount, array $payer): string
    {
        $response = Http::asJson()
            ->timeout(30)
            ->post($this->url('/acceptance/payment_keys'), [
                'auth_token' => $token,
                'amount_cents' => $this->toMinorUnit($amount),
                'expiration' => (int) config('paymob.expiration', 3600),
                'order_id' => $orderId,
                'currency' => config('paymob.currency', 'EGP'),
                'integration_id' => (int) config('paymob.integration_id'),
                'billing_data' => $this->billingData($payer),
            ]);

        $this->assertOk($response, 'payment_key');

        return (string) $response->json('token');
    }

    /**
     * Paymob rejects empty billing fields, so unknown values are sent as "NA".
     *
     * @param  array<string, mixed>  $payer
     * @return array<string, string>
     */
    protected function billingData(array $payer): array
    {
        $na = 'NA';

        return [
            'first_name' => $payer['first_name'] ?? $na,
            'last_name' => $payer['last_name'] ?? $na,
            'email' => $payer['email'] ?? 'na@example.com',
            'phone_number' => $payer['phone'] ?? $na,
            'apartment' => $na,
            'floor' => $na,
            'building' => $na,
            'street' => $payer['street'] ?? $na,
            'city' => $payer['city'] ?? $na,
            'state' => $payer['state'] ?? $na,
            'country' => $payer['country'] ?? 'EG',
            'postal_code' => $payer['postal_code'] ?? $na,
            'shipping_method' => $na,
        ];
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * HMAC-SHA512 over Paymob's fixed field order. The order is part of their
     * spec — changing it silently breaks verification, so it is spelled out.
     *
     * @param  array<string, mixed>  $data
     */
    protected function expectedHmac(array $data): string
    {
        $fields = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order.id',
            'owner',
            'pending',
            'source_data.pan',
            'source_data.sub_type',
            'source_data.type',
            'success',
        ];

        $concatenated = '';

        foreach ($fields as $field) {
            $value = data_get($data, $field);

            // Booleans must be lowercase "true"/"false" to match Paymob.
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $concatenated .= $value === null ? '' : (string) $value;
        }

        return hash_hmac('sha512', $concatenated, (string) config('paymob.hmac_secret'));
    }

    /**
     * Convert a major-unit amount to integer minor units (piastres).
     */
    protected function toMinorUnit(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function url(string $path): string
    {
        return rtrim((string) config('paymob.base_url'), '/').'/api'.$path;
    }

    protected function assertOk(\Illuminate\Http\Client\Response $response, string $step): void
    {
        if ($response->successful()) {
            return;
        }

        Log::error('Paymob '.$step.' request failed.', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \RuntimeException(__('lang_v1.payment_gateway_error'));
    }
}
