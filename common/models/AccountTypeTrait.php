<?php

namespace common\models;

/**
 * Account type (user.role) helpers for the user models. The account type decides which version of
 * a page someone gets (a patient's calendar or a consultant's) and the RBAC role they start with;
 * it is not a permission. What someone may do is checked with Yii::$app->user->can().
 *
 * @property string|null $role
 */
trait AccountTypeTrait
{
    /** A patient account; legacy accounts without a type are patients. */
    public function isPatientAccount()
    {
        return in_array($this->role, [User::ACCOUNT_PATIENT, null, ''], true);
    }

    public function isConsultantAccount()
    {
        return $this->role === User::ACCOUNT_CONSULTANT;
    }

    public function isFrontDeskAccount()
    {
        return $this->role === User::ACCOUNT_FRONT_DESK;
    }
}
