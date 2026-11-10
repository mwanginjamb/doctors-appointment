<?php

namespace frontend\components;

use common\components\RbacHelper;
use common\rbac\AssignedConsultantRule;
use frontend\models\Appointments;
use Yii;

/**
 * Shortcuts for the staff permissions around appointments. Every answer comes from RBAC
 * (Yii::$app->user->can()); see RbacHelper::definitions() for who holds what:
 *
 *  - front desk handles only the consultants assigned to them (rule assignedConsultant);
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
     * May book, move and register walk-ins in this consultant's calendar: the consultant themselves
     * or their front desk.
     *
     * @param int $consultantUserId Appointments::consultant_id
     */
    public static function canBookFor($consultantUserId)
    {
        return Yii::$app->user->can(RbacHelper::PERM_BOOK_FOR_CONSULTANT, ['consultantUserId' => (int) $consultantUserId]);
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
    }
}
