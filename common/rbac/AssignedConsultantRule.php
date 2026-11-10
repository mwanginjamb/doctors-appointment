<?php

namespace common\rbac;

use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;

/**
 * Passes when the user is front desk assigned to the consultant the check is about
 * (front_desk_assignment). Assignments are looked up once per user per request.
 */
class AssignedConsultantRule extends ConsultantContextRule
{
    public $name = 'assignedConsultant';

    /** @var array user id => consultant user ids */
    private static $cache = [];

    public function execute($user, $item, $params)
    {
        $consultantUserId = $this->consultantUserId($params);

        return $consultantUserId !== null && in_array($consultantUserId, self::consultantUserIdsFor($user), true);
    }

    /**
     * @return int[] user ids of the consultants assigned to a front-desk user
     */
    public static function consultantUserIdsFor($userId)
    {
        if (!isset(self::$cache[$userId])) {
            self::$cache[$userId] = array_map('intval', Consultant::find()
                ->select('user_id')
                ->where(['id' => FrontDeskAssignment::consultantIdsFor($userId)])
                ->column());
        }

        return self::$cache[$userId];
    }

    /** Forgets cached assignments (after they change, and between tests). */
    public static function reset()
    {
        self::$cache = [];
    }
}
