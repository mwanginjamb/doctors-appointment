<?php

namespace frontend\components;

use common\components\RbacHelper;
use common\rbac\AssignedConsultantRule;
use common\rbac\AssignedFacilityRule;
use frontend\models\Appointments;
use Yii;

/**
 * Shortcuts for the staff permissions around appointments. Every answer comes from RBAC
 * (Yii::$app->user->can()); see RbacHelper::definitions() for who holds what:
 *
 *  - front desk handles only the consultants assigned to them (rule assignedConsultant), and only
 *    at the facility they work at (rule assignedFacility, F8 phase 2);
 *  - a consultant handles their own calendar (rule isTheConsultant);
 *  - admins see and cancel every appointment, but do not book.
 *
 * Consultants are identified by their user id, as Appointments::consultant_id is.
 */
class FrontDesk
{
    /** user.role of front-desk accounts */
    const ROLE = \common\models\User::ACCOUNT_FRONT_DESK;

    /**
     * A front-desk account: decides which version of a page they get (account type, not a permission).
     */
    public static function isFrontDesk()
    {
        return !Yii::$app->user->isGuest && Yii::$app->user->identity->isFrontDeskAccount();
    }

    /** May open the appointments board. */
    public static function isStaff()
    {
        return Yii::$app->user->can(RbacHelper::PERM_VIEW_CLINIC_BOARD);
    }

    /**
     * Consultant user ids the current user handles on the board: null means all, [] none.
     *
     * @return int[]|null
     */
    public static function consultantUserIds()
    {
        if (Yii::$app->user->isGuest) {
            return [];
        }
        if (Yii::$app->user->can(RbacHelper::PERM_VIEW_ALL_APPOINTMENTS)) {
            return null;
        }

        return AssignedConsultantRule::consultantUserIdsFor(Yii::$app->user->id);
    }

    /**
     * The facility the current user handles appointments at: a front-desk account's own facility.
     * Null means every facility (admins, consultants, and front desk with no facility set yet).
     *
     * @return int|null
     */
    public static function facilityId()
    {
        if (Yii::$app->user->isGuest || !Yii::$app->user->identity->isFrontDeskAccount()) {
            return null;
        }

        return AssignedFacilityRule::facilityIdFor(Yii::$app->user->id);
    }

    /**
     * Limits an appointments query to those the current staff user sees in full on the board and in
     * the numbers: every one for admins; front desk, their consultants' at their facility.
     *
     * @param \yii\db\ActiveQuery $query
     * @param string $alias the appointments table alias in the query, e.g. 'a'
     */
    public static function scope($query, $alias = 'appointments')
    {
        $consultants = self::consultantUserIds();
        if ($consultants !== null) {
            // [0] matches nothing when no consultant is assigned
            $query->andWhere(["$alias.consultant_id" => $consultants ?: [0]]);
        }
        if (($facilityId = self::facilityId()) !== null) {
            $query->andWhere(["$alias.facility_id" => $facilityId]);
        }

        return $query;
    }

    /**
     * Which of a patient's appointments the current user may see in their history (past
     * appointments, outcomes, the no-show count), as a query condition:
     *
     *  - admins: every one (an empty condition);
     *  - a consultant: their own, at every facility, never other consultants';
     *  - front desk: their consultants' at their facility;
     *  - anyone else: none.
     *
     * @return array|string '0=1' when none
     */
    public static function historyCondition($alias = 'appointments')
    {
        if (Yii::$app->user->isGuest) {
            return '0=1';
        }
        if (Yii::$app->user->can(RbacHelper::PERM_VIEW_ALL_APPOINTMENTS)) {
            return [];
        }
        $identity = Yii::$app->user->identity;
        if ($identity->isConsultantAccount()) {
            return ["$alias.consultant_id" => (int) $identity->id];
        }
        if ($identity->isFrontDeskAccount()) {
            $condition = ['and', ["$alias.consultant_id" => self::consultantUserIds() ?: [0]]];
            if (($facilityId = self::facilityId()) !== null) {
                $condition[] = ["$alias.facility_id" => $facilityId];
            }
            return $condition;
        }

        return '0=1';
    }

    /**
     * May book, move and register walk-ins in this consultant's calendar: the consultant themselves
     * or their front desk (at their own facility, which is checked per booking).
     *
     * @param int $consultantUserId Appointments::consultant_id
     */
    public static function canBookFor($consultantUserId)
    {
        return Yii::$app->user->can(RbacHelper::PERM_BOOK_FOR_CONSULTANT, ['consultantUserId' => (int) $consultantUserId]);
    }

    /**
     * May book or move this appointment where it is now: the consultant themselves, or their front
     * desk when it is at their facility.
     */
    public static function canBookAppointment(Appointments $appointment)
    {
        return Yii::$app->user->can(RbacHelper::PERM_BOOK_FOR_CONSULTANT, ['appointment' => $appointment]);
    }

    /** May open and cancel this appointment as staff: admins, and the consultant's front desk. */
    public static function canHandle(Appointments $appointment)
    {
        return Yii::$app->user->can(RbacHelper::PERM_HANDLE_APPOINTMENT, ['appointment' => $appointment]);
    }

    /** May record attended / no-show: the appointment's consultant, their front desk, admins. Never the patient. */
    public static function canRecordOutcome(Appointments $appointment)
    {
        return Yii::$app->user->can(RbacHelper::PERM_RECORD_OUTCOME, ['appointment' => $appointment]);
    }

    /** Forgets cached assignments (after they change, and between tests). */
    public static function reset()
    {
        AssignedConsultantRule::reset();
        AssignedFacilityRule::reset();
    }
}
