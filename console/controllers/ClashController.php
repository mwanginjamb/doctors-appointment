<?php

namespace console\controllers;

use frontend\services\Clashes;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * F14: bookings that clash with a closure, time off or holiday.
 *
 *   php yii clash/remind   the day before, remind staff of patients told who have not moved (daily)
 */
class ClashController extends Controller
{
    public function actionRemind()
    {
        $lines = Clashes::remindStaff();
        $this->stdout(($lines ? implode("\n", $lines) : 'Nobody to remind about.') . "\n");

        return ExitCode::OK;
    }
}
