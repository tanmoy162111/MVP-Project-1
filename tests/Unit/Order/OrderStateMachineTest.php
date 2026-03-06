<?php

namespace Tests\Unit\Order;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderStateMachine;
use App\Exceptions\InvalidOrderTransitionException;
use Tests\TestCase;

/**
 * Pure unit tests — no database, no HTTP.
 * Tests the state machine logic in complete isolation.
 */
class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new OrderStateMachine();
    }

    // ── VALID TRANSITIONS ─────────────────────────────────────────────────────

    public function test_pending_can_move_to_confirmed(): void
    {
        $this->assertTrue($this->machine->canTransition('pending', 'confirmed'));
    }

    public function test_pending_can_move_to_payment_pending(): void
    {
        $this->assertTrue($this->machine->canTransition('pending', 'payment_pending'));
    }

    public function test_pending_can_be_cancelled(): void
    {
        $this->assertTrue($this->machine->canTransition('pending', 'cancelled'));
    }

    public function test_confirmed_can_move_to_processing(): void
    {
        $this->assertTrue($this->machine->canTransition('confirmed', 'processing'));
    }

    public function test_processing_can_move_to_shipped(): void
    {
        $this->assertTrue($this->machine->canTransition('processing', 'shipped'));
    }

    public function test_shipped_can_move_to_delivered(): void
    {
        $this->assertTrue($this->machine->canTransition('shipped', 'delivered'));
    }

    public function test_delivered_can_request_refund(): void
    {
        $this->assertTrue($this->machine->canTransition('delivered', 'refund_requested'));
    }

    public function test_refund_requested_can_be_refunded(): void
    {
        $this->assertTrue($this->machine->canTransition('refund_requested', 'refunded'));
    }

    public function test_on_hold_can_return_to_confirmed(): void
    {
        $this->assertTrue($this->machine->canTransition('on_hold', 'confirmed'));
    }

    // ── INVALID TRANSITIONS ───────────────────────────────────────────────────

    public function test_pending_cannot_jump_to_shipped(): void
    {
        $this->assertFalse($this->machine->canTransition('pending', 'shipped'));
    }

    public function test_delivered_cannot_go_back_to_processing(): void
    {
        $this->assertFalse($this->machine->canTransition('delivered', 'processing'));
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertFalse($this->machine->canTransition('cancelled', 'pending'));
        $this->assertFalse($this->machine->canTransition('cancelled', 'confirmed'));
        $this->assertEmpty($this->machine->nextStatuses('cancelled'));
    }

    public function test_refunded_is_terminal(): void
    {
        $this->assertFalse($this->machine->canTransition('refunded', 'pending'));
        $this->assertEmpty($this->machine->nextStatuses('refunded'));
    }

    public function test_shipped_cannot_be_cancelled(): void
    {
        $this->assertFalse($this->machine->canTransition('shipped', 'cancelled'));
    }

    public function test_processing_cannot_jump_to_delivered(): void
    {
        $this->assertFalse($this->machine->canTransition('processing', 'delivered'));
    }

    // ── NEXT STATUSES ─────────────────────────────────────────────────────────

    public function test_next_statuses_returns_correct_options_for_confirmed(): void
    {
        $next = $this->machine->nextStatuses('confirmed');

        $this->assertContains('processing', $next);
        $this->assertContains('cancelled',  $next);
        $this->assertContains('on_hold',    $next);
        $this->assertNotContains('shipped', $next);
        $this->assertNotContains('pending', $next);
    }

    public function test_next_statuses_empty_for_terminal_states(): void
    {
        $this->assertEmpty($this->machine->nextStatuses('cancelled'));
        $this->assertEmpty($this->machine->nextStatuses('refunded'));
    }
}
