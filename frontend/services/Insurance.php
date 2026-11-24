<?php

namespace frontend\services;

use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\Provider;
use Yii;

/**
 * F18: insured bookings. Every booking asks cash or insurance (whatever the commitment fee); an
 * insured one gives the insurer (from the insurers list) and member number, filled in from the
 * patient's last insured booking. An insurer the consultant does not list is warned about on the
 * form and flagged on the booking, not refused.
 *
 * Each insured booking's pre-authorisation starts pending; the consultant, their front desk at
 * its facility, the facility's admins and consultancy admins record it approved (with the
 * insurer's reference) or declined (with a note). A decline is sent to the patient.
 */
class Insurance
{
    const PREAUTH_PENDING = 'pending';
    const PREAUTH_APPROVED = 'approved';
    const PREAUTH_DECLINED = 'declined';

    public static function preauthLabels()
    {
        return [
            self::PREAUTH_PENDING => Yii::t('app', 'Pre-auth pending'),
            self::PREAUTH_APPROVED => Yii::t('app', 'Pre-auth approved'),
            self::PREAUTH_DECLINED => Yii::t('app', 'Pre-auth declined'),
        ];
    }

    /** @return array provider id => name, by name; without "None" (no cover), which an insured booking cannot have */
    public static function insurers()
    {
        return Provider::find()->select('provider')->where(['not', ['provider' => null]])->andWhere(['not in', 'provider', ['', 'None']])
            ->orderBy(['provider' => SORT_ASC])->indexBy('id')->column();
    }

    /** @return int[] the insurers (provider ids) the consultant lists */
    public static function acceptedIds(?Consultant $consultant)
    {
        if ($consultant === null || empty($consultant->covers_supported)) {
            return [];
        }
        $ids = is_array($consultant->covers_supported) ? $consultant->covers_supported : explode(',', (string) $consultant->covers_supported);

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** The insurer and member number of the patient's last insured booking: [insurer id, member number] or nulls. */
    public static function lastFor($patientId)
    {
        $row = Appointments::find()->select(['insurer_id', 'member_number'])
            ->where(['patient_id' => (int) $patientId, 'payment_mode' => FeeRules::PAYMENT_INSURANCE])
            ->andWhere(['not', ['insurer_id' => null]])
            ->orderBy(['id' => SORT_DESC])->asArray()->one();

        return [$row ? (int) $row['insurer_id'] : null, $row['member_number'] ?? null];
    }

    /** The booking form's insurer answers onto the appointment (called by FeeRules::load()). */
    public static function load(Appointments $appointment, array $data)
    {
        $appointment->insuranceAsked = true;
        if ($appointment->payment_mode === FeeRules::PAYMENT_INSURANCE) {
            $insurer = (int) ($data['insurer_id'] ?? 0);
            $appointment->insurer_id = $insurer ?: null;
            $appointment->member_number = trim(mb_substr((string) ($data['member_number'] ?? ''), 0, 40)) ?: null;
        } else {
            $appointment->insurer_id = null;
            $appointment->member_number = null;
        }
    }

    /** A new insured booking: pre-authorisation pending, and whether the consultant lists the insurer. */
    public static function onInsert(Appointments $appointment)
    {
        if ($appointment->payment_mode !== FeeRules::PAYMENT_INSURANCE || !$appointment->insurer_id) {
            return;
        }
        $appointment->preauth_status = self::PREAUTH_PENDING;
        $consultant = Consultant::findOne(['user_id' => $appointment->consultant_id]);
        $appointment->insurer_listed = in_array((int) $appointment->insurer_id, self::acceptedIds($consultant), true) ? 1 : 0;
    }

    /** Who records pre-authorisation: whoever records the outcome of this appointment. */
    public static function canRecord(Appointments $appointment)
    {
        return $appointment->preauth_status !== null && FrontDesk::canRecordOutcome($appointment);
    }

    /**
     * Records approved or declined (or back to pending). Declined is sent to the patient.
     *
     * @return string|null why not, or null when saved
     */
    public static function record(Appointments $appointment, $status, $reference = '', $note = '')
    {
        if (!array_key_exists($status, self::preauthLabels())) {
            return Yii::t('app', 'Choose approved, declined or pending.');
        }
        $reference = trim(mb_substr((string) $reference, 0, 64));
        $note = trim(mb_substr((string) $note, 0, 255));
        $was = $appointment->preauth_status;
        $appointment->updateAttributes([
            'preauth_status' => $status,
            'preauth_reference' => $reference !== '' ? $reference : null,
            'preauth_note' => $note !== '' ? $note : null,
            'preauth_by' => Yii::$app->user->isGuest ? null : (int) Yii::$app->user->id,
            'preauth_at' => time(),
        ]);
        if ($status === self::PREAUTH_DECLINED && $was !== self::PREAUTH_DECLINED) {
            self::tellDeclined($appointment);
        }

        return null;
    }

    /** "Jubilee · member 12345", or null for a cash booking. */
    public static function summary(Appointments $appointment)
    {
        if ($appointment->payment_mode !== FeeRules::PAYMENT_INSURANCE || !$appointment->insurer_id) {
            return null;
        }
        $name = self::insurers()[$appointment->insurer_id] ?? Yii::t('app', 'Insurer');

        return $name . ($appointment->member_number ? ' · ' . Yii::t('app', 'member {n}', ['n' => $appointment->member_number]) : '');
    }

    private static function tellDeclined(Appointments $appointment)
    {
        $patient = $appointment->patient;
        if ($patient === null) {
            return;
        }
        $doctor = $appointment->consultant->names ?? 'your doctor';
        $insurer = self::insurers()[$appointment->insurer_id] ?? 'Your insurer';
        $when = date('l j F Y \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time));
        $text = 'Hello ' . ($patient->full_name ?: 'there') . ",\n\n{$insurer} did not approve your visit to {$doctor} on {$when}"
            . ($appointment->preauth_note ? " ({$appointment->preauth_note})" : '') . '. '
            . 'You can still come and pay for the visit yourself, check your insurance details with the clinic, or change or cancel the appointment.';
        $link = SelfServiceLink::urlIfUseful($appointment);
        if ($patient->email) {
            Yii::$app->queue->push(new SendEmailMessageJob(array_filter([
                'to' => $patient->email, 'toName' => $patient->full_name ?: null,
                'subject' => "Your insurer did not approve your visit to {$doctor}", 'text' => $text,
                'linkUrl' => $link, 'linkLabel' => $link ? 'Change or cancel the appointment' : null,
            ])));
        }
        if ($patient->phone_number && \common\models\Consent::remindersAllowed($patient->id)) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $patient->phone_number, 'text' => $text . ($link ? "\n\n" . $link : '')]));
        }
    }
}
