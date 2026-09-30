<?php

declare(strict_types=1);

/*
 * Permissions granted to the 'Tech Support' role (App\Maxeme\Security\StaffRole). Edit this file to change what
 * the role can do. Names are App\Maxeme\Security\Permission constants: '/area/view', '/area/edit',
 * '/area/*' for both, '/*' for everything.
 *
 * Everything, like Super Admin (core's role; it also inherits ROLE_SUPER_ADMIN in security.yaml).
 */

return [
    '/*',
];
