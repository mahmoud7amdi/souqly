<?php

namespace App\Contracts;

use App\Models\Transaction;

/**
 * A checkout provider for the public invoice-payment page (`/pay/{token}`).
 *
 * Only Paymob is implemented; the contract exists so the invoice-payment flow
 * has no provider-specific code in it and a second provider stays additive.
 */
interface PaymentGateway
{
    /**
     * Machine key used in config and stored on the payment row (`gateway`).
     */
    public function key(): string;

    /**
     * Human label for the checkout button.
     */
    public function label(): string;

    /**
     * True when the provider has enough configuration to be used.
     */
    public function isConfigured(): bool;

    /**
     * Start a checkout for an invoice.
     *
     * @param  float  $amount  amount in the business' major currency unit
     * @param  array<string, mixed>  $payer  name / email / phone of the payer
     * @return array{redirect_url?: string, iframe_url?: string, reference: string, raw: array<string, mixed>}
     */
    public function initiate(Transaction $transaction, float $amount, array $payer = []): array;

    /**
     * Verify a provider callback and report the outcome.
     *
     * MUST return false for any payload whose authenticity cannot be proven —
     * this is what stops a forged "paid" callback from settling an invoice.
     *
     * @param  array<string, mixed>  $payload
     * @return array{verified: bool, success: bool, reference: string|null, amount: float|null, order_reference: string|null}
     */
    public function verifyCallback(array $payload): array;
}
