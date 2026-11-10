<?php

namespace console\controllers;

use common\components\HealthCheck;
use common\components\HealthMonitor;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Health checks (T2): queue worker, reminder scheduler, message delivery, WhatsApp session, database.
 */
class HealthController extends Controller
{
    /**
     * Runs the checks, emails alerts (new problems, reminders every few hours, resolved) to
     * ALERT_EMAILS and pings HEALTHCHECK_PING_URL. Run every 5 minutes by
     * doctors-appointment-health.timer.
     *
     * Usage: php yii health/check
     */
    public function actionCheck()
    {
        $results = (new HealthCheck())->run();
        $monitor = new HealthMonitor();
        $alerts = $monitor->process($results);
        $sent = $monitor->send($alerts);
        $healthy = !in_array(false, array_column($results, 'ok'), true);
        $monitor->ping($healthy);

        $this->printResults($results);
        if ($alerts) {
            $this->stdout(count($alerts) . ' alert(s) ' . ($sent ? 'emailed' : 'due, but not emailed (see the log)') . ".\n");
        }

        return ExitCode::OK;
    }

    /**
     * Runs the checks and prints them, without alerting.
     *
     * Usage: php yii health/status
     */
    public function actionStatus()
    {
        $results = (new HealthCheck())->run();
        $this->printResults($results);

        return in_array(false, array_column($results, 'ok'), true) ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Sends a test error to the error tracker (GlitchTip), to check SENTRY_DSN.
     *
     * Usage: php yii health/test-error
     */
    public function actionTestError()
    {
        if ((string) (\Yii::$app->params['monitoring.dsn'] ?? '') === '') {
            $this->stderr("SENTRY_DSN is not set: error tracking is off.\n");
            return ExitCode::CONFIG;
        }
        \Yii::error(new \RuntimeException('Test error from php yii health/test-error. You can resolve it.'), __METHOD__);
        \Yii::getLogger()->flush(true);
        $this->stdout("Test error sent. It should appear in GlitchTip within a few seconds.\n");

        return ExitCode::OK;
    }

    private function printResults(array $results)
    {
        $labels = HealthCheck::labels();
        foreach ($results as $key => $result) {
            $mark = $result['ok'] ? $this->ansiFormat('OK  ', Console::FG_GREEN) : $this->ansiFormat('FAIL', Console::FG_RED);
            $this->stdout(sprintf("%s  %-20s %s\n", $mark, $labels[$key] ?? $key, $result['message']));
        }
    }
}
