<?php

namespace console\controllers;

use frontend\services\CommitmentFees;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * F22: the commitment fee's background work.
 */
class FeeController extends Controller
{
    /**
     * Releases bookings whose 10-minute hold ran out unpaid, after asking M-Pesa about each payment
     * still pending; the patient is told and can book again. Run every minute.
     *
     * Usage: php yii fee/release
     */
    public function actionRelease()
    {
        $released = CommitmentFees::releaseExpired();
        if ($released) {
            $this->stdout("$released unpaid bookings released.\n");
        }

        return ExitCode::OK;
    }
}
