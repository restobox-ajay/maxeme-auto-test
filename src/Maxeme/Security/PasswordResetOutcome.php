<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

/** What happened to a "forgot password" request (legacy FOS ResettingController::sendEmailAction). */
enum PasswordResetOutcome
{
    /** The reset email was sent. */
    case Sent;

    /** No account has that username or email. */
    case UnknownUser;

    /** A link sent earlier is still valid, so no new one is sent. */
    case AlreadyRequested;
}
