<?php

namespace common\rbac;

/**
 * Passes when the check is about the facility the user runs as facility admin (user.facility_id).
 *
 * The facility is taken from the check's params, and must be named:
 *
 *   ['facilityId' => 3]
 *   ['facility' => Facility]          its id
 *   ['appointment' => Appointments]   its facility_id
 *
 * Unlike assignedFacility (front desk), params that name no facility fail, and so does an account
 * with no facility set: a facility admin only ever acts on their own facility.
 */
class FacilityAdminRule extends \yii\rbac\Rule
{
    public $name = 'facilityAdmin';

    public function execute($user, $item, $params)
    {
        $own = AssignedFacilityRule::facilityIdFor($user);
        if ($own === null) {
            return false;
        }
        $facilityId = self::facilityIdIn($params);

        return $facilityId !== null && $facilityId === $own;
    }

    /**
     * The facility a check's params are about, or null when they name none.
     */
    public static function facilityIdIn(array $params)
    {
        if (array_key_exists('facilityId', $params)) {
            return $params['facilityId'] !== null && $params['facilityId'] !== '' ? (int) $params['facilityId'] : null;
        }
        if (isset($params['facility'])) {
            return (int) $params['facility']->id;
        }
        if (isset($params['appointment'])) {
            return $params['appointment']->facility_id !== null ? (int) $params['appointment']->facility_id : null;
        }

        return null;
    }
}
