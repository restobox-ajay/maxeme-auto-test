<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

use App\Maxeme\Entity\RepairOrder;

/**
 * The Work Order Board's categories (spec item 21): where the vehicle is, from booked to paid and
 * gone. Derived from the repair order (of()); a cancelled repair order is on none.
 */
enum WorkOrderStage: string
{
    /** Booked: the estimate is being built, or approved, and the car isn't in yet. */
    case AppointmentMade = 'appointment_made';
    /** The quote was sent; waiting on the customer. */
    case PendingOnQuote = 'pending_on_quote';
    /** In the shop, no technician on it yet. */
    case CheckIn = 'check_in';
    /** A master technician is on it (the work order is issued), or it waits for parts. */
    case Repairing = 'repairing';
    /** Work done, not paid yet (waiting for pickup, or picked up and unpaid). */
    case CompletePendingPickUp = 'complete_pending_pick_up';
    /** Billed and paid. */
    case CompletedPaid = 'completed_paid';

    public static function of(RepairOrder $repairOrder): ?self
    {
        return match ($repairOrder->getStatus()) {
            RepairOrderStatus::EstimateBeingBuilt, RepairOrderStatus::Authorized => self::AppointmentMade,
            RepairOrderStatus::EstimateApproval => self::PendingOnQuote,
            RepairOrderStatus::InProgress => $repairOrder->getMasterTechnician() === null ? self::CheckIn : self::Repairing,
            RepairOrderStatus::AwaitingParts => self::Repairing,
            RepairOrderStatus::Completed, RepairOrderStatus::CompletedPickedUp => self::CompletePendingPickUp,
            RepairOrderStatus::Invoiced => self::CompletedPaid,
            RepairOrderStatus::Cancelled => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AppointmentMade => 'Appointment Made',
            self::PendingOnQuote => 'Pending on Quote',
            self::CheckIn => 'Check-in',
            self::Repairing => 'Repairing (Work Order Issued)',
            self::CompletePendingPickUp => 'Complete, Pending on Pick Up',
            self::CompletedPaid => 'Completed (Paid)',
        };
    }

    /** The repair order statuses it covers, for the board's explanation. */
    public function covers(): string
    {
        return match ($this) {
            self::AppointmentMade => 'Estimate Being Built, Authorized',
            self::PendingOnQuote => 'Estimate Approval',
            self::CheckIn => 'In Progress, no master technician',
            self::Repairing => 'In Progress with a master technician, Awaiting Parts',
            self::CompletePendingPickUp => 'Completed; Completed, Picked Up',
            self::CompletedPaid => 'Invoiced',
        };
    }
}
