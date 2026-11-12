<?php

namespace common\rbac;

use yii\db\Query;
use yii\rbac\Rule;

/**
 * F8 phase 2. Passes when the check is about the facility the front-desk user works at
 * (user.facility_id). It sits on top of assignedConsultant, so front desk handles an assigned
 * consultant's appointments only at their own facility.
 *
 * The facility is taken from the check's params:
 *
 *   ['facilityId' => 3]
 *   ['appointment' => Appointments]   its facility_id
 *
 * Params that name no facility (opening a consultant's calendar) pass: the consultant rule still
 * applies, and every booking is checked again once its time has settled where it is
 * (BookingService). An appointment with no facility is not theirs. A front-desk account with no
 * facility set (not chosen yet by an admin) is not limited.
 */
class AssignedFacilityRule extends Rule
{
    public $name = 'assignedFacility';

    /** @var array user id => facility id or null */
    private static $cache = [];

    public function execute($user, $item, $params)
    {
        $own = self::facilityIdFor($user);
        if ($own === null) {
            return true;
        }
        if (array_key_exists('facilityId', $params)) {
            return $params['facilityId'] !== null && (int) $params['facilityId'] === $own;
        }
        if (isset($params['appointment'])) {
            $facilityId = $params['appointment']->facility_id;
            return $facilityId !== null && (int) $facilityId === $own;
        }

        return true;
    }

    /**
     * The facility a front-desk user works at, or null when none is set.
     */
    public static function facilityIdFor($userId)
    {
        if (!array_key_exists($userId, self::$cache)) {
            $id = (new Query())->select('facility_id')->from('{{%user}}')->where(['id' => $userId])->scalar();
            self::$cache[$userId] = $id !== null && $id !== false ? (int) $id : null;
        }

        return self::$cache[$userId];
    }

    /** Forgets cached facilities (after they change, and between tests). */
    public static function reset()
    {
        self::$cache = [];
    }
}
