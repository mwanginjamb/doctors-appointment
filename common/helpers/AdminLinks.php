<?php

namespace common\helpers;

use Yii;

/**
 * Links between the two admin areas on the same domain: the main site (frontend, at /) keeps the
 * platform admins' pages for consultants, facilities, staff, RBAC and the audit log; the admin
 * area (backend, at /admin) has pilots, payments, refunds and payouts.
 *
 * Built on APP_PUBLIC_URL (params['app.publicUrl']) when set, else as paths on this host.
 */
class AdminLinks
{
    /** The admin area's home, /admin/. */
    public static function adminArea()
    {
        return self::base() . '/admin/';
    }

    /** A page of the main site, e.g. mainSite('manage-consultants/index'). */
    public static function mainSite($path = '')
    {
        return self::base() . '/' . ltrim((string) $path, '/');
    }

    private static function base()
    {
        return rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');
    }
}
