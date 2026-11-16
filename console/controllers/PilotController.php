<?php

namespace console\controllers;

use frontend\models\Consultant;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use frontend\services\Pilot;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * F20: the pilot's agreements and each consultant's pilot, until the platform admin screens (F28).
 */
class PilotController extends Controller
{
    /** @var string version signed, for pilot/agreement */
    public $version = '1.2';
    /** @var string Y-m-d, for pilot/agreement; today by default */
    public $signedOn;
    /** @var bool whether same-day walk-ins at reception pay no commitment fee, for pilot/agreement */
    public $walkInExempt = true;

    public function options($actionID)
    {
        $options = parent::options($actionID);
        if ($actionID === 'agreement') {
            $options = array_merge($options, ['version', 'signedOn', 'walkInExempt']);
        }

        return $options;
    }

    /**
     * Each consultant's pilot: first booking, today's pilot day and phase, and their facilities'
     * agreements.
     *
     * Usage: php yii pilot/status
     */
    public function actionStatus()
    {
        $consultants = Consultant::find()->orderBy(['first_booking_at' => SORT_ASC, 'names' => SORT_ASC])->all();
        if (!$consultants) {
            $this->stdout("No consultants.\n");
            return ExitCode::OK;
        }
        $labels = Pilot::phaseLabels();
        foreach ($consultants as $consultant) {
            $first = $consultant->first_booking_at ? date('Y-m-d', $consultant->first_booking_at) : '—';
            $day = Pilot::day($consultant);
            $this->stdout(sprintf("%-30s first booking %-10s  day %-4s %s\n",
                mb_substr((string) $consultant->names, 0, 30), $first, $day ?? '—', $labels[Pilot::phase($consultant)]));
        }

        $this->stdout("\nAgreements:\n");
        foreach (Facility::find()->orderBy('name')->all() as $facility) {
            $agreement = FacilityAgreement::currentFor($facility->id);
            $this->stdout(sprintf("  %-30s %s\n", mb_substr($facility->name, 0, 30), $agreement
                ? "v{$agreement->version}, signed {$agreement->signed_on} by {$agreement->facility_signatory}"
                    . ($agreement->walk_in_exempt ? '' : ', walk-ins pay')
                    . ($agreement->ended_on ? ", left {$agreement->ended_on}" : '')
                : 'none signed'));
        }

        return ExitCode::OK;
    }

    /**
     * Records the agreement a facility signed.
     *
     * Usage: php yii pilot/agreement <facility id> "<facility signatory>" "<platform signatory>"
     *        [--version=1.2] [--signedOn=Y-m-d] [--walkInExempt=0]
     */
    public function actionAgreement($facilityId, $facilitySignatory, $platformSignatory)
    {
        $agreement = new FacilityAgreement([
            'facility_id' => (int) $facilityId,
            'version' => $this->version,
            'signed_on' => $this->signedOn ?: date('Y-m-d'),
            'facility_signatory' => $facilitySignatory,
            'platform_signatory' => $platformSignatory,
            'walk_in_exempt' => $this->walkInExempt ? 1 : 0,
        ]);
        if (!$agreement->save()) {
            foreach ($agreement->getFirstErrors() as $error) {
                $this->stderr($error . "\n", Console::FG_RED);
            }
            return ExitCode::DATAERR;
        }
        $this->stdout("Recorded agreement v{$agreement->version} for {$agreement->facility->name} (#{$agreement->id}).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Clears a consultant's first booking, so their pilot starts at their next booking. For test
     * bookings made before the pilot; the date otherwise never moves.
     *
     * Usage: php yii pilot/restart <consultant user id>
     */
    public function actionRestart($userId)
    {
        $consultant = Consultant::findOne(['user_id' => (int) $userId]);
        if ($consultant === null) {
            $this->stderr("No consultant with user id $userId.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        if (!$this->confirm("Clear the pilot start of {$consultant->names}? Their pilot starts again at their next booking.")) {
            return ExitCode::OK;
        }
        $consultant->updateAttributes(['first_booking_at' => null]);
        $this->stdout("Cleared. {$consultant->names}'s pilot starts at their next booking.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
