<?php

namespace frontend\components;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use Yii;

/**
 * Who may handle other people's appointments, and for which consultants.
 *
 *  - Front desk (user.role = 'front_desk') sees, books, moves and cancels appointments, but only
 *    for the consultants an admin assigned to them.
 *  - Admins see and cancel every appointment, but do not book (their calendars are read-only).
 *
 * Consultants are identified by their user id here, as Appointments::consultant_id is.
 */
class FrontDesk
{
    const ROLE = 'front_desk';

    /** @var array user id => consultant user ids, per request */
    private static $cache = [];

    public static function isFrontDesk()
    {
        return !Yii::$app->user->isGuest && Yii::$app->user->identity->role === self::ROLE;
    }

    /**
     * Admins or front desk: the people who use the appointments board.
     */
    public static function isStaff()
    {
        return AdminAccessControl::isAdmin() || self::isFrontDesk();
    }

    /**
     * Consultant user ids the current user handles: null means all (admins), [] none.
     *
     * @return int[]|null
     */
    public static function consultantUserIds()
    {
        if (AdminAccessControl::isAdmin()) {
            return null;
        }
        if (!self::isFrontDesk()) {
            return [];
        }

        $userId = Yii::$app->user->id;
        if (!isset(self::$cache[$userId])) {
            self::$cache[$userId] = array_map('intval', Consultant::find()
                ->select('user_id')
                ->where(['id' => FrontDeskAssignment::consultantIdsFor($userId)])
                ->column());
        }

        return self::$cache[$userId];
    }

    /**
     * Whether the current user is front desk for this consultant, i.e. may book, move and cancel
     * their appointments for patients.
     *
     * @param int $consultantUserId Appointments::consultant_id
     */
    public static function canBookFor($consultantUserId)
    {
        return self::isFrontDesk() && in_array((int) $consultantUserId, self::consultantUserIds(), true);
    }

    /**
     * Whether the current user may open and cancel this appointment as staff.
     */
    public static function canHandle(Appointments $appointment)
    {
        return AdminAccessControl::isAdmin() || self::canBookFor($appointment->consultant_id);
    }

    /**
     * Whether the current user may record attended / no-show: the appointment's consultant,
     * front desk assigned to them, or an admin. Never the patient.
     */
    public static function canRecordOutcome(Appointments $appointment)
    {
        if (Yii::$app->user->isGuest) {
            return false;
        }

        return $appointment->isConsultant(Yii::$app->user->id) || self::canHandle($appointment);
    }

    /** Forgets cached assignments (after they change, and between tests). */
    public static function reset()
    {
        self::$cache = [];
    }
}
