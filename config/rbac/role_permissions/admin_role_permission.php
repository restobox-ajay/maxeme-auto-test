<?php

declare(strict_types=1);

/*
 * Permissions granted to the 'Admin' role (App\Maxeme\Security\StaffRole). Edit this file to change what
 * the role can do. Names are App\Maxeme\Security\Permission constants: '/area/view', '/area/edit',
 * '/area/*' for both, '/*' for everything.
 *
 * Role matrix: Edit on every area. Manage Admins: adds accounts of any role and de-activates them,
 * except Super Admin and Tech Support accounts, which it cannot change (StaffAccountVoter).
 */

return [
    '/account/manage',
    '/appointment/*',   // Schedule appointment: Edit
    '/reminder/*',      // Schedule reminder: Edit
    '/service/*',       // Service: Edit
    '/parts/*',         // Parts and service: Edit
    '/work-order/*',    // Work Order: Edit
    '/accounting/*',    // Accounting: Edit
    '/people/*',        // People: Edit
    '/car/*',           // Car: Edit
    '/staff/*',
];
