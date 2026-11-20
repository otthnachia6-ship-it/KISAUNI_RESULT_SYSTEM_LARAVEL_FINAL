<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;
use Illuminate\Http\Request;

/**
 * First (global) maintenance check, before sessions exist. Visitors without the
 * Developer's signed bypass cookie are stopped here.
 *
 * The bypass cookie only gets a request past THIS check. Because a cookie belongs to a
 * browser, not to a person, EnforceMaintenanceForSchoolUsers (web group, after the
 * session starts) then requires the signed-in account to be the Developer. So when the
 * Developer signs out and a school user signs in on the same browser, they still see
 * the maintenance page.
 */
class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * Always reachable during maintenance. The Developer Control Center stays open so the
     * Developer can always sign in and switch maintenance off, even from another browser.
     * Every page there still requires the Developer account (others get the login page or 404).
     *
     * @var array<int, string>
     */
    protected $except = self::ALWAYS_OPEN;

    public const ALWAYS_OPEN = [
        'developer',
        'developer/*',
        'logout',
    ];

    public static function isAlwaysOpen(Request $request): bool
    {
        return $request->is(...self::ALWAYS_OPEN);
    }
}
