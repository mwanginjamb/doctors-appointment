<?php

namespace backend\models;

use backend\components\PlatformAdminOnly;
use common\models\LoginForm;

/**
 * Sign-in to the backend: the usual username and password (shared with the frontend), accepted
 * only for platform admins. Others get the same message as a wrong password, so the page does not
 * tell who is an admin.
 */
class AdminLoginForm extends LoginForm
{
    public function validatePassword($attribute, $params)
    {
        parent::validatePassword($attribute, $params);
        if (!$this->hasErrors() && !PlatformAdminOnly::isPlatformAdmin($this->getUser()->id ?? null)) {
            $this->addError($attribute, 'Incorrect username or password.');
            \common\components\Audit::loginFailed($this->username . ' (admin area: not a platform admin)', $this->getUser()->id ?? null);
        }
    }
}
