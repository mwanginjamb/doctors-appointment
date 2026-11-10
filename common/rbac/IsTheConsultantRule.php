<?php

namespace common\rbac;

/**
 * Passes when the user is the consultant the check is about: their own calendar, their own
 * appointment, their own profile.
 */
class IsTheConsultantRule extends ConsultantContextRule
{
    public $name = 'isTheConsultant';

    public function execute($user, $item, $params)
    {
        $consultantUserId = $this->consultantUserId($params);

        return $consultantUserId !== null && (int) $user === $consultantUserId;
    }
}
