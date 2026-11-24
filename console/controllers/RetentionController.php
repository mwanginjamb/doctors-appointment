<?php

namespace console\controllers;

use frontend\models\Appointments;
use frontend\services\PatientData;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * F12 retention rules for patients' data.
 */
class RetentionController extends Controller
{
    /** @var bool only report what would change */
    public $dryRun = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), $actionID === 'run' ? ['dryRun'] : []);
    }

    public function optionAliases()
    {
        return ['n' => 'dryRun'];
    }

    /**
     * Applies the retention rules (retention.* params, from .env):
     *  - a patient account with no sign-in and no appointment for RETENTION_INACTIVE_YEARS (3) is
     *    anonymised, like a deletion on request; one the clinic still owes money is skipped;
     *  - symptom notes are cleared RETENTION_SYMPTOM_MONTHS (12) after the visit;
     *  - email sign-ups never verified are removed after RETENTION_UNVERIFIED_DAYS (30).
     * Each change is in the audit log. Run daily by doctors-appointment-retention.timer.
     *
     * Usage: php yii retention/run [--dry-run]
     */
    public function actionRun()
    {
        $params = Yii::$app->params;
        $years = (int) ($params['retention.inactiveYears'] ?? 3);
        $months = (int) ($params['retention.symptomMonths'] ?? 12);
        $days = (int) ($params['retention.unverifiedDays'] ?? 30);

        // 1. Inactive patients
        $anonymised = 0;
        $skipped = [];
        foreach (PatientData::inactivePatients()->each(100) as $user) {
            if ($problems = PatientData::deletionProblems($user)) {
                $skipped[] = "  #{$user->id}: " . implode(' ', $problems);
                continue;
            }
            if (!$this->dryRun) {
                PatientData::anonymise($user, "inactive for $years years");
            }
            $anonymised++;
        }
        $this->stdout(($this->dryRun ? 'Would anonymise' : 'Anonymised') . " $anonymised patient accounts with nothing for $years years.\n");
        if ($skipped) {
            $this->stdout(count($skipped) . " skipped, the clinic still owes them money:\n" . implode("\n", $skipped) . "\n");
        }

        // 2. Symptom notes
        $symptoms = $this->dryRun
            ? (int) Appointments::find()->where(PatientData::oldSymptomsCondition())->count()
            : PatientData::clearOldSymptoms();
        $this->stdout(($this->dryRun ? 'Would clear' : 'Cleared') . " symptom notes on $symptoms visits older than $months months.\n");

        // 3. Never-verified sign-ups
        $removed = 0;
        foreach (PatientData::staleSignups()->all() as $user) {
            if (!$this->dryRun) {
                PatientData::removeSignup($user);
            }
            $removed++;
        }
        $this->stdout(($this->dryRun ? 'Would remove' : 'Removed') . " $removed email sign-ups not verified within $days days.\n");

        if (!$this->dryRun && ($anonymised || $removed)) {
            \common\components\Audit::log(\common\models\AuditLog::ACTION_PURGED, 'retention', null, [],
                "$anonymised inactive patient accounts anonymised, $removed unverified sign-ups removed");
        }
        if ($this->dryRun) {
            $this->stdout("Dry run: nothing changed.\n");
        }

        return ExitCode::OK;
    }
}
