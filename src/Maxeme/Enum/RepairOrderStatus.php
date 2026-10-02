<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** Where a repair order is, from the estimate being built to the car gone and billed. */
enum RepairOrderStatus: string
{
    /** Estimate being built, nothing authorized. */
    case EstimateBeingBuilt = 'estimate_being_built';
    /** Sent, waiting on the customer (set when the estimate is sent). */
    case EstimateApproval = 'estimate_approval';
    /** The customer approved; work can start. */
    case Authorized = 'authorized';
    /** A technician is working on it. */
    case InProgress = 'in_progress';
    /** Can't finish: waiting for parts. */
    case AwaitingParts = 'awaiting_parts';
    /** Work done, ready for pickup. */
    case Completed = 'completed';
    /** The car is gone. */
    case CompletedPickedUp = 'completed_picked_up';
    /** Billed, paid, closed. */
    case Invoiced = 'invoiced';
    /** The customer walked; no work done. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::EstimateBeingBuilt => 'Estimate Being Built',
            self::EstimateApproval => 'Estimate Approval',
            self::Authorized => 'Authorized',
            self::InProgress => 'In Progress',
            self::AwaitingParts => 'Awaiting Parts',
            self::Completed => 'Completed',
            self::CompletedPickedUp => 'Completed, Picked Up',
            self::Invoiced => 'Invoiced',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The badge colour class (core's .badge variants). */
    public function badge(): string
    {
        return match ($this) {
            self::EstimateBeingBuilt, self::EstimateApproval => 'warn',
            self::Authorized, self::InProgress => 'info',
            self::AwaitingParts, self::Cancelled => 'danger',
            self::Completed, self::CompletedPickedUp, self::Invoiced => 'success',
        };
    }

    /** Before work starts: a check-in moves it to In Progress. */
    public function isBeforeWork(): bool
    {
        return in_array($this, [self::EstimateBeingBuilt, self::EstimateApproval, self::Authorized], true);
    }
}
