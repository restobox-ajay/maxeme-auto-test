<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Security\Permission;
use App\Service\AuditLogger;

/**
 * The shop's named actions that change no record, so core's automatic entity diff never sees them:
 * signing in and out, a refused page, a document downloaded or emailed. Written through core's
 * AuditLogger into the same audit_log, where AuditLogEnricher adds the role.
 */
final class ActivityRecorder
{
    public const SIGNED_IN = 'signed_in';
    public const SIGN_IN_FAILED = 'sign_in_failed';
    public const SIGNED_OUT = 'signed_out';
    public const ACCESS_DENIED = 'access_denied';
    public const DOWNLOADED = 'downloaded';
    public const EMAILED = 'emailed';
    public const EXPORTED = 'exported';

    public const AREA_SIGN_IN = 'Sign-in';
    public const AREA_SECURITY = 'Security';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function signedIn(): void
    {
        $this->auditLogger->log(self::AREA_SIGN_IN, 'AdminUser', null, self::SIGNED_IN, 'Signed in.');
    }

    public function signInFailed(string $identifier, string $reason): void
    {
        $this->auditLogger->log(self::AREA_SIGN_IN, 'AdminUser', null, self::SIGN_IN_FAILED, sprintf('Failed sign-in as "%s": %s', mb_substr($identifier, 0, 180), $reason));
    }

    public function signedOut(): void
    {
        $this->auditLogger->log(self::AREA_SIGN_IN, 'AdminUser', null, self::SIGNED_OUT, 'Signed out.');
    }

    public function accessDenied(string $method, string $path, string $reason): void
    {
        $this->auditLogger->log(self::AREA_SECURITY, 'Route', null, self::ACCESS_DENIED, sprintf('Refused %s %s (%s).', $method, $path, $reason));
    }

    public function downloaded(Invoice $invoice, DocumentKind $kind): void
    {
        $this->auditLogger->log(self::area($kind), 'Invoice', $invoice->getId(), self::DOWNLOADED, sprintf('Downloaded %s.', $kind->filename($invoice)));
    }

    public function emailed(Invoice $invoice, DocumentKind $kind, string $recipients): void
    {
        $this->auditLogger->log(self::area($kind), 'Invoice', $invoice->getId(), self::EMAILED, sprintf('Emailed %s to %s.', $kind->filename($invoice), $recipients));
    }

    /**
     * A list exported to CSV: which list, how many rows, and the view it was (search and sort).
     *
     * @param string $area a Permission::AREAS key, e.g. 'people'
     */
    public function exported(string $area, string $entityType, string $filename, int $rows, string $view): void
    {
        $this->auditLogger->log(Permission::AREAS[$area], $entityType, null, self::EXPORTED, sprintf('Exported %d rows to %s (%s).', $rows, $filename, $view));
    }

    private static function area(DocumentKind $kind): string
    {
        return Permission::AREAS[$kind === DocumentKind::Invoice ? 'accounting' : 'work-order'];
    }
}
