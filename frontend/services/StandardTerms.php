<?php

namespace frontend\services;

use common\components\RbacHelper;
use common\jobs\SendEmailMessageJob;
use common\models\User;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use Yii;

/**
 * A facility's move to standard terms after its pilot (see Pilot for the days), in two steps:
 *
 * 1. The facility confirms. Day 81: its facility admin and front desk are emailed that standard
 *    terms start in 10 days, with a button to the terms page, where they confirm. Day 91: the same,
 *    again, if not confirmed; a 30-day grace period starts, with a warning on every page for the
 *    facility's consultants, front desk and facility admin. Day 121, still not confirmed: platform
 *    admins are emailed; the warning stays.
 * 2. A platform admin approves. Confirming emails the platform admins, and they see a warning
 *    until they approve; approving emails the facility.
 *
 * Nothing is charged on standard terms until they are approved (Pilot::onStandardTerms()).
 * Notices need a recorded agreement (php yii pilot/agreement): it is what gets confirmed.
 */
class StandardTerms
{
    /**
     * Step 1: the facility confirms its current agreement's standard terms, and platform admins are
     * asked to approve. False when it has no agreement or it is already confirmed.
     *
     * @param int|null $userId who confirmed; null from the console
     */
    public static function confirm($facilityId, $userId = null)
    {
        $agreement = FacilityAgreement::currentFor($facilityId);
        if ($agreement === null || $agreement->standard_terms_confirmed_at !== null) {
            return false;
        }
        $agreement->standard_terms_confirmed_at = time();
        $agreement->standard_terms_confirmed_by = $userId;
        if (!$agreement->save(false, ['standard_terms_confirmed_at', 'standard_terms_confirmed_by', 'updated_at', 'updated_by'])) {
            return false;
        }

        $who = $userId ? User::findOne($userId) : null;
        $facility = $agreement->facility;
        self::emailPlatformAdmins(
            "{$facility->name} confirmed standard terms: please approve",
            "{$facility->name} confirmed the move to standard terms"
                . ($who ? ' (' . ($who->full_name ?: $who->username) . ')' : '')
                . ". They apply once you approve them on the terms page.",
            $facility,
            'Review and approve'
        );

        return true;
    }

    /**
     * Step 2: a platform admin approves terms the facility confirmed; standard terms apply from
     * then, and the facility is told. False when not confirmed yet, or already approved.
     *
     * @param int|null $userId who approved; null from the console
     */
    public static function approve($facilityId, $userId = null)
    {
        $agreement = FacilityAgreement::currentFor($facilityId);
        if ($agreement === null || $agreement->standard_terms_confirmed_at === null || $agreement->standard_terms_approved_at !== null) {
            return false;
        }
        $agreement->standard_terms_approved_at = time();
        $agreement->standard_terms_approved_by = $userId;
        if (!$agreement->save(false, ['standard_terms_approved_at', 'standard_terms_approved_by', 'updated_at', 'updated_by'])) {
            return false;
        }

        $facility = $agreement->facility;
        foreach (self::recipients($facility->id) as $user) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $user->email,
                'toName' => $user->full_name ?: null,
                'subject' => "Standard terms are approved at {$facility->name}",
                'text' => 'Dear ' . ($user->full_name ?: $user->username) . ",\n\n"
                    . "The platform has approved the standard terms {$facility->name} confirmed. They now apply to each "
                    . "consultant there from day 91 of their own pilot.",
                'linkUrl' => self::termsUrl($facility->id),
                'linkLabel' => 'See the terms',
            ]));
        }

        return true;
    }

    /**
     * Sends the notices that are due on $at, each once per agreement. Run daily by
     * `php yii pilot/notify`.
     *
     * @return string[] what was sent, one line each
     */
    public static function sendDueNotices($at = null)
    {
        $sent = [];
        foreach (Facility::find()->orderBy('id')->all() as $facility) {
            $agreement = FacilityAgreement::currentFor($facility->id);
            // Once the facility confirmed, the platform admins were asked to approve (confirm())
            if ($agreement === null || $agreement->standard_terms_confirmed_at !== null || $agreement->ended_on) {
                continue;
            }
            $status = Pilot::termsStatus($facility->id, $at);

            if ($status === Pilot::TERMS_UPCOMING && !$agreement->notice_upcoming_sent_at) {
                $count = self::emailFacility($facility, false);
                $agreement->updateAttributes(['notice_upcoming_sent_at' => time()]);
                $sent[] = "{$facility->name}: standard terms start on " . Pilot::standardTermsStart($facility->id) . " ($count emailed)";
            } elseif (in_array($status, [Pilot::TERMS_GRACE, Pilot::TERMS_OVERDUE], true) && !$agreement->notice_grace_sent_at) {
                $count = self::emailFacility($facility, true);
                $agreement->updateAttributes(['notice_grace_sent_at' => time()]);
                $sent[] = "{$facility->name}: grace period until " . Pilot::graceEnds($facility->id) . " ($count emailed)";
            }

            if ($status === Pilot::TERMS_OVERDUE && !$agreement->notice_overdue_sent_at) {
                $count = self::emailPlatformAdmins(
                    "{$facility->name} has not confirmed standard terms",
                    "The 30-day grace period at {$facility->name} ended on " . Pilot::graceEnds($facility->id)
                        . " without the standard terms being confirmed. Consultants there are not being charged.\n\n"
                        . 'Please contact the facility, or confirm the terms for them on the terms page once agreed.',
                    $facility,
                    'Open the terms page'
                );
                $agreement->updateAttributes(['notice_overdue_sent_at' => time()]);
                $sent[] = "{$facility->name}: still not confirmed after the grace period ($count platform admins emailed)";
            }
        }

        return $sent;
    }

    /** The facility admins and front desk of the facility, active and with an email. */
    public static function recipients($facilityId)
    {
        $adminIds = FacilityAdmins::query($facilityId)->select('id')->column();

        return User::find()->where(['facility_id' => (int) $facilityId, 'status' => User::STATUS_ACTIVE])
            ->andWhere(['or', ['role' => User::ACCOUNT_FRONT_DESK], ['id' => $adminIds ?: [0]]])
            ->andWhere(['not', ['email' => null]])->andWhere(['<>', 'email', ''])->all();
    }

    /**
     * The warnings for the signed-in user: for a facility's consultants, front desk and facility
     * admin, its terms waiting for the facility to confirm (grace period or overdue); for platform
     * admins, every facility waiting for their approval.
     *
     * @return array[] each ['facility' => Facility, 'status' => string, 'graceEnds' => Y-m-d|null]
     */
    public static function warningsFor($identity, $at = null)
    {
        if ($identity === null) {
            return [];
        }
        $warnings = [];
        foreach (Pilot::facilitiesOf($identity) as $facilityId) {
            $status = Pilot::termsStatus($facilityId, $at);
            if (!in_array($status, [Pilot::TERMS_GRACE, Pilot::TERMS_OVERDUE], true)) {
                continue;
            }
            $facility = Facility::findOne($facilityId);
            if ($facility !== null && FacilityAgreement::currentFor($facilityId) !== null) {
                $warnings[] = ['facility' => $facility, 'status' => $status, 'graceEnds' => Pilot::graceEnds($facilityId)];
            }
        }

        if (Pilot::canApprove($identity)) {
            $waiting = FacilityAgreement::find()
                ->where(['not', ['standard_terms_confirmed_at' => null]])
                ->andWhere(['standard_terms_approved_at' => null])
                ->select('facility_id')->distinct()->column();
            foreach ($waiting as $facilityId) {
                if (Pilot::termsStatus($facilityId, $at) === Pilot::TERMS_AWAITING_APPROVAL && ($facility = Facility::findOne($facilityId))) {
                    $warnings[] = ['facility' => $facility, 'status' => Pilot::TERMS_AWAITING_APPROVAL, 'graceEnds' => null];
                }
            }
        }

        return $warnings;
    }

    /** The terms page of a facility, absolute, for emails. */
    public static function termsUrl($facilityId)
    {
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');

        return $base . '/terms/' . (int) $facilityId;
    }

    private static function emailFacility(Facility $facility, $started)
    {
        $start = Pilot::standardTermsStart($facility->id);
        $subject = $started
            ? "Standard terms have started at {$facility->name}: please confirm"
            : "Standard terms start at {$facility->name} on $start";
        $text = $started
            ? "The 90-day pilot at {$facility->name} has ended, and standard terms apply from $start.\n\n"
                . 'Please review and confirm them by ' . Pilot::graceEnds($facility->id) . '. Until they are confirmed '
                . 'and the platform has approved them, consultants are not charged, and everyone at the facility '
                . 'sees a reminder in the app.'
            : "The 90-day pilot at {$facility->name} ends soon: standard terms apply from $start.\n\n"
                . 'Under standard terms, each active consultant chooses a plan: Plan A (Ksh 1,000 a month, with the '
                . 'Ksh 250 patient commitment fee and the Ksh 50 front-desk incentive) or Plan B (Ksh 1,500 a month, '
                . "no patient fee).\n\nPlease review and confirm them on the terms page; the platform then approves them.";

        $count = 0;
        foreach (self::recipients($facility->id) as $user) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $user->email,
                'toName' => $user->full_name ?: null,
                'subject' => $subject,
                'text' => 'Dear ' . ($user->full_name ?: $user->username) . ",\n\n" . $text,
                'linkUrl' => self::termsUrl($facility->id),
                'linkLabel' => 'Review and confirm the terms',
            ]));
            $count++;
        }

        return $count;
    }

    private static function emailPlatformAdmins($subject, $text, Facility $facility, $linkLabel)
    {
        $ids = Yii::$app->authManager->getUserIdsByRole(RbacHelper::ROLE_ADMIN);
        $count = 0;
        foreach (User::find()->where(['id' => $ids, 'status' => User::STATUS_ACTIVE])->all() as $user) {
            if (!$user->email) {
                continue;
            }
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $user->email,
                'toName' => $user->full_name ?: null,
                'subject' => $subject,
                'text' => $text,
                'linkUrl' => self::termsUrl($facility->id),
                'linkLabel' => $linkLabel,
            ]));
            $count++;
        }

        return $count;
    }
}
