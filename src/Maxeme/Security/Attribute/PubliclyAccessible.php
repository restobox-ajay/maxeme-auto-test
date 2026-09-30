<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Attribute;

/**
 * Opts a Maxeme action out of PermissionEnforcementSubscriber's secure-by-default check, for pages
 * reached without signing in (forgot / reset password). It does not bypass the firewall: the path
 * also needs PUBLIC_ACCESS in security.yaml's access_control.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class PubliclyAccessible
{
}
