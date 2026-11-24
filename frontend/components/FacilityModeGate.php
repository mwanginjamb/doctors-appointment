<?php

namespace frontend\components;

use frontend\services\FacilityAdmins;
use yii\base\Application;
use yii\base\Behavior;

/**
 * Applies the "managing my facility" switch of a consultant who runs a facility to their account
 * at the start of each request (see FacilityAdmins). Attached in frontend/config/main.php.
 */
class FacilityModeGate extends Behavior
{
    public function events()
    {
        return [Application::EVENT_BEFORE_ACTION => 'apply'];
    }

    public function apply()
    {
        FacilityAdmins::applyToIdentity();
    }
}
