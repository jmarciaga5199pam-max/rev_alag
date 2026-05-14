<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Allow doctors plus SUPERADMIN to access /doctor/* routes so the
 * superadmin can perform any doctor-level action.
 */
class DoctorMiddleware extends RoleMiddleware
{
    public function __construct()
    {
        parent::__construct('DOCTOR', 'DOCTOR_OWNER', 'SUPERADMIN');
    }
}
