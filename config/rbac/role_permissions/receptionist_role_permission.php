<?php

declare(strict_types=1);

/*
 * Permissions granted to the 'Receptionist' role (App\Maxeme\Security\StaffRole). Edit this file to change what
 * the role can do. Names are App\Maxeme\Security\Permission constants: '/area/view', '/area/edit',
 * '/area/*' for both, '/*' for everything.
 *
 * Role matrix: Edit on the schedule, People and Car; View on Service, Parts, Work Order and Accounting.
 */

return [
    '/account/manage',
    '/appointment/*',   // Schedule appointment: Edit
    '/reminder/*',      // Schedule reminder: Edit
    '/service/view',    // Service: View
    '/parts/view',      // Parts and service: View
    '/work-order/view', // Work Order: View
    '/accounting/view', // Accounting: View
    '/people/*',        // People: Edit
    '/car/*',           // Car: Edit
];
