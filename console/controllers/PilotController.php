<?php

namespace console\controllers;

use frontend\models\Consultant;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use frontend\services\Pilot;
use frontend\services\StandardTerms;
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
            $day = Pilot::facilityDay($facility->id);
            $this->stdout(sprintf("  %-30s %s\n", mb_substr($facility->name, 0, 30), $agreement
                ? "v{$agreement->version}, signed {$agreement->signed_on} by {$agreement->facility_signatory}"
                    . ($agreement->walk_in_exempt ? '' : ', walk-ins pay')
                    . ($agreement->ended_on ? ", left {$agreement->ended_on}" : '')
                    . '; pilot day ' . ($day ?? '—') . ', standard terms: ' . Pilot::termsLabels()[Pilot::termsStatus($facility->id)]
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
     * Step 1 for a facility, by a platform admin acting on the facility's word: confirms its
     * standard terms. Its front desk or facility admin normally confirm on its terms page
     * (/terms/<facility id>). A platform admin then approves (pilot/approve-standard).
     *
     * Usage: php yii pilot/confirm-standard <facility id>
     */
    public function actionConfirmStandard($facilityId)
    {
        $facility = Facility::findOne((int) $facilityId);
        if ($facility === null) {
            $this->stderr("No facility $facilityId.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        if (!StandardTerms::confirm($facility->id)) {
            $this->stderr("{$facility->name} has no agreement recorded, or its standard terms are already confirmed.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $this->stdout("Standard terms confirmed for {$facility->name}. Approve them with: php yii pilot/approve-standard {$facility->id}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Step 2: approves standard terms the facility confirmed; they apply from then, and the facility
     * is told. Platform admins can also approve on the terms page.
     *
     * Usage: php yii pilot/approve-standard <facility id>
     */
    public function actionApproveStandard($facilityId)
    {
        $facility = Facility::findOne((int) $facilityId);
        if ($facility === null) {
            $this->stderr("No facility $facilityId.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        if (!StandardTerms::approve($facility->id)) {
            $this->stderr("{$facility->name} has not confirmed standard terms yet, or they are already approved.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $this->stdout("Standard terms approved for {$facility->name}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Sends the standard-terms notices that are due: day 81 and day 91 to the facility admin and
     * front desk, day 121 (still unconfirmed) to platform admins. Each is sent once; run daily.
     *
     * Usage: php yii pilot/notify
     */
    public function actionNotify()
    {
        $sent = StandardTerms::sendDueNotices();
        foreach ($sent as $line) {
            $this->stdout($line . "\n");
        }
        $this->stdout(count($sent) . " notices.\n");

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
