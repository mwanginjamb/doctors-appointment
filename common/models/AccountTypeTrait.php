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
    /**
     * Keeps user.phone_key (the number in one form, for finding accounts by number) in step with
     * phone_number. A changed number is no longer proven, so phone_verified_at is cleared.
     */
    protected function syncPhoneKey()
    {
        if (!$this->hasAttribute('phone_key')) {
            return; // before the phone sign-in migration
        }
        $key = \common\helpers\Phone::normalize($this->phone_number);
        if ($key !== $this->getAttribute('phone_key')) {
            if (!$this->isNewRecord) {
                $this->setAttribute('phone_verified_at', null);
            }
            $this->setAttribute('phone_key', $key);
        }
    }

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

    /** Runs one facility (user.facility_id). */
    public function isFacilityAdminAccount()
    {
        return $this->role === User::ACCOUNT_FACILITY_ADMIN;
    }
}
