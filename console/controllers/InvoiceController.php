<?php

namespace console\controllers;

use frontend\services\Invoices;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * F25: consultants' monthly invoices. Platform admins see, record and void them in the admin area.
 */
class InvoiceController extends Controller
{
    /**
     * Issues last month's invoices (or those of the month given), one per consultant with something
     * to bill, and sends them. Safe to run again: a consultant's month is issued once. Run on the 1st
     * of each month by doctors-appointment-invoices.timer.
     *
     * Usage: php yii invoice/issue [Y-m]
     */
    public function actionIssue($month = null)
    {
        if ($month !== null && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->stderr("The month is Y-m, e.g. 2026-12.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $done = Invoices::issueMonth($month ? $month . '-01' : null);
        foreach ($done as $line) {
            $this->stdout($line . "\n");
        }
        if (Invoices::enabled()) {
            $this->stdout(count($done) . " invoices issued.\n");
        }

        return ExitCode::OK;
    }

    /**
     * The daily run: reminds consultants of invoices due today and a week overdue, pauses patients'
     * online booking with those whose invoice with standard-terms charges is 14 days overdue, and
     * lifts pauses no longer owed. Run daily by doctors-appointment-invoice-reminders.timer.
     *
     * Usage: php yii invoice/remind
     */
    public function actionRemind()
    {
        $done = Invoices::remindAndPause();
        foreach ($done as $line) {
            $this->stdout($line . "\n");
        }
        if (Invoices::enabled() && !$done) {
            $this->stdout("Nothing to remind or pause.\n");
        }

        return ExitCode::OK;
    }
}
