<?php

declare(strict_types=1);

/*
 * Permissions granted to the 'Super Admin' role (App\Maxeme\Security\StaffRole). Edit this file to change what
 * the role can do. Names are App\Maxeme\Security\Permission constants: '/area/view', '/area/edit',
 * '/area/*' for both, '/*' for everything.
 *
 * Everything, including Manage Admins (adding accounts of any role, de-activating them) and editing a paid invoice
 * (InvoiceVoter).
 */

return [
    '/*',
];
