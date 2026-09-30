<?php

declare(strict_types=1);

/*
 * Permissions granted to the 'Technician' role (App\Maxeme\Security\StaffRole). Edit this file to change what
 * the role can do. Names are App\Maxeme\Security\Permission constants: '/area/view', '/area/edit',
 * '/area/*' for both, '/*' for everything.
 *
 * Role matrix: View on the schedule, Service and Parts; Edit on Work Order and Car; no Accounting or
 * People.
 */

return [
    '/account/manage',
    '/appointment/view', // Schedule appointment: View
    '/reminder/view',    // Schedule reminder: View
    '/service/view',     // Service: View
    '/parts/view',       // Parts and service: View
    '/work-order/*',     // Work Order: Edit
    '/car/*',            // Car: Edit
];
