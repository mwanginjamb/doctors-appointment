<?php

namespace console\controllers;

use frontend\services\Waitlist;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * F4 waiting list.
 */
class WaitlistController extends Controller
{
    /**
     * Ends waiting-list entries that no longer make sense and tells patients about times that opened
     * since they were last told (new hours, time off removed, notices held over quiet hours).
     * Run from cron every 15 minutes: php yii waitlist/sweep
     */
    public function actionSweep()
    {
        if (!Waitlist::isEnabled()) {
            $this->stdout("The waiting list is switched off (WAITLIST=0).\n");
            return ExitCode::OK;
        }
        try {
            $result = Waitlist::sweep();
            $this->stdout("Checked {$result['checked']} consultant(s); sent {$result['sent']} notice(s).\n");
            return ExitCode::OK;
        } catch (\Throwable $e) {
            Yii::error('Waiting-list sweep failed: ' . $e->getMessage(), 'waitlist');
            $this->stderr('Waiting-list sweep failed: ' . $e->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
