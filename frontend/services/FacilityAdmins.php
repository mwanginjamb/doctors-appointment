<?php

namespace frontend\services;

use common\components\Audit;
use common\components\RbacHelper;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use common\models\User;
use common\rbac\AssignedFacilityRule;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FacilityAdminClaim;
use frontend\models\FacilityRequest;
use Yii;

/**
 * Consultants who run a facility are its facility admin as well.
 *
 * A consultant claims a facility they work at from its row on their Availability page; a platform
 * admin approves or declines in /admin → Facility admins. A consultant who asks for a new facility
 * and says they run it becomes its admin when it is listed. Granting sets user.facility_id and adds
 * the facility-admin RBAC role next to the consultant's own; a consultant runs one facility at a
 * time. Platform admins revoke it in /admin.
 *
 * Such a consultant switches between their own practice and managing the facility (the account
 * menu): while managing it, their account behaves as a facility admin's (isFacilityAdminAccount()),
 * so the board, front desk, closed days and reports are the facility's. Facility notices (clash
 * reminders, invoice lines, standard terms) reach them in either mode.
 */
class FacilityAdmins
{
    /** Session key: the consultant is managing their facility */
    const SESSION_KEY = 'facilityMode';

    /** @var array user id => facility id|null, for this request */
    private static $runs = [];

    /**
     * The facility the user runs as its admin: a facility admin account's facility, or the facility
     * an approved consultant runs. Null when none.
     *
     * @param User $user
     */
    public static function runsFacilityId($user)
    {
        if ($user === null) {
            return null;
        }
        $id = (int) $user->id;
        if (!array_key_exists($id, self::$runs)) {
            $facilityId = $user->facility_id !== null ? (int) $user->facility_id : null;
            if ($user->role === User::ACCOUNT_FACILITY_ADMIN) {
                self::$runs[$id] = $facilityId;
            } elseif ($user->role === User::ACCOUNT_CONSULTANT && $facilityId) {
                self::$runs[$id] = Yii::$app->authManager->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $id) !== null ? $facilityId : null;
            } else {
                self::$runs[$id] = null;
            }
        }

        return self::$runs[$id];
    }

    /** Forgets what was worked out (after a change, and between tests). */
    public static function reset()
    {
        self::$runs = [];
        AssignedFacilityRule::reset();
    }

    /** A consultant (not a facility admin account) who runs a facility. */
    public static function isConsultantAdmin($user)
    {
        return $user !== null && $user->role === User::ACCOUNT_CONSULTANT && self::runsFacilityId($user) !== null;
    }

    /**
     * Everyone who runs these facilities: facility admin accounts and consultants who run them.
     *
     * @param int|int[] $facilityIds
     * @return \yii\db\ActiveQuery of User
     */
    public static function query($facilityIds)
    {
        $facilityIds = (array) $facilityIds;
        $consultantAdmins = Yii::$app->authManager->getUserIdsByRole(RbacHelper::ROLE_FACILITY_ADMIN);

        return User::find()
            ->where(['facility_id' => $facilityIds ?: [0]])
            ->andWhere(['or',
                ['role' => User::ACCOUNT_FACILITY_ADMIN],
                ['and', ['role' => User::ACCOUNT_CONSULTANT], ['id' => $consultantAdmins ?: [0]]],
            ]);
    }

    // ---- Managing the facility (the switch) ----------------------------------------------------

    /** Whether the signed-in consultant is managing their facility. */
    public static function managing()
    {
        $user = Yii::$app->get('user', false);
        if ($user === null || $user->isGuest) {
            return false;
        }
        $identity = $user->identity;

        return !empty($identity->facilityMode);
    }

    /**
     * Turns managing on or off for the signed-in consultant.
     *
     * @return bool false when they run no facility
     */
    public static function setManaging($on)
    {
        $identity = Yii::$app->user->identity;
        if ($on && !self::isConsultantAdmin($identity)) {
            return false;
        }
        Yii::$app->session->set(self::SESSION_KEY, (bool) $on);
        $identity->facilityMode = (bool) $on;

        return true;
    }

    /**
     * Applies the switch to the signed-in user, at the start of each request (FacilityModeGate).
     * Dropped when they no longer run a facility.
     */
    public static function applyToIdentity()
    {
        $user = Yii::$app->user;
        if ($user->isGuest || !Yii::$app->session->get(self::SESSION_KEY)) {
            return;
        }
        $identity = $user->identity;
        if (self::isConsultantAdmin($identity)) {
            $identity->facilityMode = true;
        } else {
            Yii::$app->session->remove(self::SESSION_KEY);
        }
    }

    // ---- Claims --------------------------------------------------------------------------------

    /** The consultant's claim waiting for an answer, if any. */
    public static function pendingClaim($userId)
    {
        return FacilityAdminClaim::find()->where(['user_id' => (int) $userId, 'status' => FacilityAdminClaim::STATUS_PENDING])->one();
    }

    /**
     * A consultant claims to run a facility they work at; a platform admin answers.
     *
     * @return FacilityAdminClaim|string the claim, or why it cannot be made
     */
    public static function claim(User $user, Facility $facility)
    {
        if ($user->role !== User::ACCOUNT_CONSULTANT) {
            return 'Only consultants can claim a facility.';
        }
        if (self::runsFacilityId($user) !== null) {
            return 'You already run a facility. Ask the administrator to change it.';
        }
        if (self::pendingClaim($user->id)) {
            return 'You already have a claim waiting for the administrator.';
        }
        $consultant = Consultant::findOne(['user_id' => $user->id]);
        if (!$consultant || !ConsultantFacility::find()->where(['consultant_id' => $consultant->id, 'facility_id' => $facility->id])->exists()) {
            return 'Add the facility to your facilities first.';
        }

        $claim = new FacilityAdminClaim([
            'user_id' => $user->id,
            'facility_id' => $facility->id,
            'status' => FacilityAdminClaim::STATUS_PENDING,
            'created_at' => time(),
        ]);
        $claim->save(false);
        self::emailPlatformAdmins($claim, $consultant);

        return $claim;
    }

    /** A facility request marked "I run this facility": its claim waits for the request. */
    public static function claimForRequest(FacilityRequest $request)
    {
        $consultant = $request->consultant;
        if (!$request->runs_facility || !$consultant || !$consultant->user_id) {
            return null;
        }
        $user = User::findOne($consultant->user_id);
        if (!$user || self::runsFacilityId($user) !== null || self::pendingClaim($user->id)) {
            return null;
        }
        $claim = new FacilityAdminClaim([
            'user_id' => $user->id,
            'facility_request_id' => $request->id,
            'status' => FacilityAdminClaim::STATUS_PENDING,
            'created_at' => time(),
        ]);
        $claim->save(false);

        return $claim;
    }

    /** The facility was listed at the consultant's request: they run it, without another approval. */
    public static function onRequestApproved(FacilityRequest $request)
    {
        $claim = FacilityAdminClaim::findOne(['facility_request_id' => $request->id, 'status' => FacilityAdminClaim::STATUS_PENDING]);
        if ($claim === null || !$request->facility_id) {
            return false;
        }
        $claim->facility_id = $request->facility_id;

        return self::approve($claim, 'They asked for the facility to be listed and run it.') === true;
    }

    /** The request was declined: so is its claim (the consultant was told about the request). */
    public static function onRequestDeclined(FacilityRequest $request)
    {
        FacilityAdminClaim::updateAll(
            ['status' => FacilityAdminClaim::STATUS_CANCELLED, 'decided_at' => time()],
            ['facility_request_id' => $request->id, 'status' => FacilityAdminClaim::STATUS_PENDING]
        );
    }

    /**
     * Grants the claim: the consultant becomes the facility's admin as well.
     *
     * @return true|string true, or why it cannot be granted
     */
    public static function approve(FacilityAdminClaim $claim, $note = null)
    {
        if (!$claim->isPending() || !$claim->facility_id) {
            return 'This claim has been answered already.';
        }
        $user = $claim->user;
        if ($user === null || $user->role !== User::ACCOUNT_CONSULTANT) {
            return 'The account is no longer a consultant.';
        }
        if (($runs = self::runsFacilityId($user)) !== null && $runs !== (int) $claim->facility_id) {
            return 'This consultant already runs another facility. Revoke that first.';
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $user->facility_id = (int) $claim->facility_id;
            $user->save(false);
            $auth = Yii::$app->authManager;
            if ($auth->getAssignment(RbacHelper::ROLE_FACILITY_ADMIN, $user->id) === null) {
                $auth->assign($auth->getRole(RbacHelper::ROLE_FACILITY_ADMIN), $user->id);
            }
            self::close($claim, FacilityAdminClaim::STATUS_APPROVED, $note);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        self::reset();
        Audit::log(\common\models\AuditLog::ACTION_ROLES_CHANGED, 'user', $user->id, [],
            'Runs ' . ($claim->facility->name ?? 'facility #' . $claim->facility_id) . ' as facility admin', null, $claim->facility_id);

        $name = $claim->facility->name ?? '';
        self::tellConsultant($user, "You now run {$name} on the app",
            "You are now the facility admin of {$name}, as well as a consultant.\n\nFrom your account menu choose \"Manage {$name}\" to see its board, its front desk, closed days and contact details; \"Back to my practice\" returns to your own calendar.");

        return true;
    }

    /** @return true|string */
    public static function decline(FacilityAdminClaim $claim, $reason = null)
    {
        if (!$claim->isPending()) {
            return 'This claim has been answered already.';
        }
        $reason = trim((string) $reason);
        self::close($claim, FacilityAdminClaim::STATUS_DECLINED, $reason === '' ? null : mb_substr($reason, 0, 500));
        if ($claim->user) {
            $name = $claim->facilityName();
            self::tellConsultant($claim->user, "Your request to run {$name}",
                "Your request to be the facility admin of {$name} was declined." . ($reason !== '' ? "\n\nReason: {$reason}" : '')
                . "\n\nYou still see patients there as before. Contact the administrator if you have questions.");
        }

        return true;
    }

    /**
     * Takes the facility admin role back from a consultant (they stay a consultant everywhere).
     *
     * @return true|string
     */
    public static function revoke(User $user, $reason = null)
    {
        if (!self::isConsultantAdmin($user)) {
            return 'This consultant does not run a facility.';
        }
        $facilityId = (int) $user->facility_id;
        $facility = Facility::findOne($facilityId);
        $auth = Yii::$app->authManager;
        $auth->revoke($auth->getRole(RbacHelper::ROLE_FACILITY_ADMIN), $user->id);
        $user->facility_id = null;
        $user->save(false);
        $reason = trim((string) $reason);
        FacilityAdminClaim::updateAll([
            'status' => FacilityAdminClaim::STATUS_REVOKED,
            'decision_note' => $reason === '' ? null : mb_substr($reason, 0, 500),
            'decided_by' => self::currentUserId(),
            'decided_at' => time(),
        ], ['user_id' => $user->id, 'status' => FacilityAdminClaim::STATUS_APPROVED]);
        self::reset();
        Audit::log(\common\models\AuditLog::ACTION_ROLES_CHANGED, 'user', $user->id, [],
            'No longer runs ' . ($facility->name ?? 'facility #' . $facilityId) . ' as facility admin', null, $facilityId);

        $name = $facility->name ?? 'the facility';
        self::tellConsultant($user, "You no longer run {$name} on the app",
            "You are no longer the facility admin of {$name}." . ($reason !== '' ? "\n\nReason: {$reason}" : '')
            . "\n\nYour own calendar and appointments are unchanged.");

        return true;
    }

    /**
     * Consultants who run a facility, with the facility.
     *
     * @return User[]
     */
    public static function consultantAdmins()
    {
        $ids = Yii::$app->authManager->getUserIdsByRole(RbacHelper::ROLE_FACILITY_ADMIN);

        return User::find()->where(['id' => $ids ?: [0], 'role' => User::ACCOUNT_CONSULTANT])
            ->andWhere(['not', ['facility_id' => null]])->orderBy(['full_name' => SORT_ASC])->all();
    }

    // ---- Helpers -------------------------------------------------------------------------------

    private static function close(FacilityAdminClaim $claim, $status, $note = null)
    {
        $claim->status = $status;
        $claim->decision_note = $note;
        $claim->decided_by = self::currentUserId();
        $claim->decided_at = time();
        $claim->save(false);
    }

    private static function currentUserId()
    {
        $user = Yii::$app->get('user', false);

        return $user && !$user->isGuest ? $user->id : null;
    }

    /** Email (and WhatsApp) to the consultant. */
    private static function tellConsultant(User $user, $subject, $body)
    {
        $consultant = Consultant::findOne(['user_id' => $user->id]);
        $name = $consultant->names ?? ($user->full_name ?: 'Doctor');
        $text = "Hello {$name},\n\n{$body}";
        $email = $user->email ?: ($consultant->consultant_email ?? null);
        if ($email) {
            Yii::$app->queue->push(new SendEmailMessageJob(['to' => $email, 'toName' => $name, 'subject' => $subject, 'text' => $text]));
        }
        $phone = $consultant->consultant_phone_number ?? $user->phone_number;
        if ($phone) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $phone, 'text' => $text]));
        }
    }

    /** Platform admins are asked to answer a claim. */
    private static function emailPlatformAdmins(FacilityAdminClaim $claim, Consultant $consultant)
    {
        $facility = $claim->facility->name ?? '';
        $doctor = $consultant->names ?: 'A consultant';
        $ids = Yii::$app->authManager->getUserIdsByRole(RbacHelper::ROLE_ADMIN);
        foreach (User::find()->where(['id' => $ids ?: [0], 'status' => User::STATUS_ACTIVE])->all() as $admin) {
            if (!$admin->email) {
                continue;
            }
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $admin->email,
                'toName' => $admin->full_name ?: null,
                'subject' => "{$doctor} says they run {$facility}",
                'text' => "{$doctor} says they run {$facility} and asks to be its facility admin as well as a consultant: its board, front desk, closed days and contact details.\n\nCheck with the facility before approving.",
                'linkUrl' => \common\helpers\AdminLinks::adminArea() . 'facility-admin/index',
                'linkLabel' => 'Approve or decline',
            ]));
        }
    }
}
