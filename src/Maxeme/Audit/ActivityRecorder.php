<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use App\Maxeme\Document\DocumentKind;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Security\Permission;
use App\Service\AuditLogger;

/**
 * The shop's named actions that change no record, so core's automatic entity diff never sees them:
 * signing in and out, a refused page, a document downloaded or emailed, a bulk data conversion
 * (written in SQL, so its rows are not diffed one by one). Written through core's
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
    public const SETTINGS_CHANGED = 'settings_changed';
    public const CONVERTED = 'converted';

    public const AREA_SIGN_IN = 'Sign-in';
    public const AREA_SECURITY = 'Security';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumbers $numbers,
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
        $this->auditLogger->log(self::area($kind), 'Invoice', $invoice->getId(), self::DOWNLOADED, sprintf('Downloaded %s.', $this->numbers->filename($invoice, $kind)));
    }

    public function emailed(Invoice $invoice, DocumentKind $kind, string $recipients): void
    {
        $this->auditLogger->log(self::area($kind), 'Invoice', $invoice->getId(), self::EMAILED, sprintf('Emailed %s to %s.', $this->numbers->filename($invoice, $kind), $recipients));
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

    /**
     * Shop settings stored in core's app_setting (the Doc Prefixes), which core's entity diff
     * deliberately skips. Records only the keys whose value changed, before and after.
     *
     * @param array<string, string> $before key => value
     * @param array<string, string> $after  key => value
     */
    public function settingsChanged(string $what, array $before, array $after): void
    {
        $changed = array_keys(array_diff_assoc($after, $before));
        if ($changed === []) {
            return;
        }

        $this->auditLogger->log(
            Permission::AREAS['settings'],
            'AppSetting',
            null,
            self::SETTINGS_CHANGED,
            sprintf('Changed %s: %s.', $what, implode(', ', array_map(static fn (string $key): string => sprintf('%s %s → %s', $key, $before[$key] ?? '', $after[$key]), $changed))),
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
        );
    }

    /**
     * A one-off conversion of existing data, e.g. legacy invoices into repair orders: one line for
     * the whole run.
     *
     * @param string $area a Permission::AREAS key
     */
    public function converted(string $area, string $entityType, string $summary): void
    {
        $this->auditLogger->log(Permission::AREAS[$area], $entityType, null, self::CONVERTED, $summary);
    }

    private static function area(DocumentKind $kind): string
    {
        return Permission::AREAS[$kind === DocumentKind::Invoice ? 'accounting' : 'work-order'];
    }
}
