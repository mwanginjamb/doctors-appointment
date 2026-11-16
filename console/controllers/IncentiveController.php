<?php

namespace console\controllers;

use frontend\models\IncentivePayout;
use frontend\services\Incentives;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * F24: the front-desk incentive's weekly payouts. They are sent or recorded in the backend.
 */
class IncentiveController extends Controller
{
    /**
     * Groups last week's earned incentives (Monday to Sunday) into one payout per front-desk user.
     * Safe to run again. Run every Monday morning by doctors-appointment-incentives.timer.
     *
     * Usage: php yii incentive/payouts
     */
    public function actionPayouts()
    {
        $payouts = Incentives::buildWeeklyPayouts();
        foreach ($payouts as $payout) {
            $this->stdout(sprintf("%s: Ksh %d for %d bookings, %s\n",
                $payout->frontDesk->full_name ?? $payout->frontDesk->username ?? "user {$payout->front_desk_id}",
                $payout->amount, $payout->incentives, IncentivePayout::statusLabels()[$payout->status]));
        }
        $this->stdout(count($payouts) . " payouts for the week.\n");

        return ExitCode::OK;
    }
}
