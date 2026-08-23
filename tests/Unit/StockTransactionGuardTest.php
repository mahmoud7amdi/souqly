<?php

namespace Tests\Unit;

use App\Services\FormattingService;
use App\Services\PaymentService;
use App\Services\ReferenceService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The stock and payment services refuse to run outside a database
 * transaction, because a half-applied movement would let the stock cache and
 * the FIFO map diverge — the exact class of corruption the original project
 * shipped repair commands for.
 *
 * This is a unit test rather than a feature test on purpose: feature tests run
 * inside `DatabaseTransactions`, so `transactionLevel()` is never 0 there and
 * the guard cannot be observed. Here the facade is stubbed so we control it.
 */
class StockTransactionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::clearResolvedInstances();
        \Mockery::close();

        parent::tearDown();
    }

    /**
     * Pretend we are outside any transaction.
     */
    private function withoutTransaction(): void
    {
        DB::shouldReceive('transactionLevel')->andReturn(0);
    }

    private function stock(): StockService
    {
        return new StockService(new FormattingService);
    }

    private function payments(): PaymentService
    {
        return new PaymentService(new ReferenceService, new FormattingService);
    }

    #[Test]
    public function adjusting_the_stock_cache_requires_a_transaction(): void
    {
        $this->withoutTransaction();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must run inside a database transaction');

        $this->stock()->adjustCachedQuantity(1, 1, 1, 5);
    }

    #[Test]
    public function consuming_stock_requires_a_transaction(): void
    {
        $this->withoutTransaction();

        $this->expectException(\LogicException::class);

        $this->stock()->consume(1, 1, 5, 1);
    }

    #[Test]
    public function releasing_stock_requires_a_transaction(): void
    {
        $this->withoutTransaction();

        $this->expectException(\LogicException::class);

        $this->stock()->release(1);
    }

    #[Test]
    public function returning_stock_to_lots_requires_a_transaction(): void
    {
        $this->withoutTransaction();

        $this->expectException(\LogicException::class);

        $this->stock()->returnToLots(1, 5);
    }

    #[Test]
    public function recording_a_payment_requires_a_transaction(): void
    {
        $this->withoutTransaction();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must run inside a database transaction');

        $this->payments()->addPayment(new \App\Models\Transaction, ['amount' => 10]);
    }

    #[Test]
    public function settling_a_contact_balance_requires_a_transaction(): void
    {
        $this->withoutTransaction();

        $this->expectException(\LogicException::class);

        $this->payments()->payContactDue(new \App\Models\Contact, ['amount' => 10]);
    }
}
