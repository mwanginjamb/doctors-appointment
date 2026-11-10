<?php

namespace common\rbac;

use yii\rbac\Rule;

/**
 * Base for rules about "which consultant is this about". The consultant (as their user id, the
 * value Appointments::consultant_id holds) is taken from the check's params:
 *
 *   ['consultantUserId' => 12]
 *   ['appointment' => Appointments]   its consultant_id
 *   ['consultant' => Consultant]      its user_id
 */
abstract class ConsultantContextRule extends Rule
{
    /**
     * @return int|null the consultant's user id, or null when the params name none
     */
    protected function consultantUserId(array $params)
    {
        if (isset($params['consultantUserId'])) {
            return (int) $params['consultantUserId'];
        }
        if (isset($params['appointment'])) {
            return (int) $params['appointment']->consultant_id;
        }
        if (isset($params['consultant'])) {
            return $params['consultant']->user_id !== null ? (int) $params['consultant']->user_id : null;
        }

        return null;
    }
}
