<?php

namespace frontend\services;

use common\components\Audit;
use common\models\AccountClosureRequest;
use common\models\AuditLog;
use common\models\Consent;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\PatientCredit;
use Yii;
use yii\db\Expression;
use yii\db\Query;

/**
 * F12: a patient's own data, exported and deleted (Kenya Data Protection Act: access, portability,
 * erasure), and the retention rules applied by `php yii retention/run`.
 *
 * Deleting anonymises rather than removes rows: the account keeps its id as "Deleted patient #id"
 * and can no longer sign in; name, contacts, date of birth, sign-in links, symptoms, insurer member
 * numbers, pre-authorisation notes, rating comments and the waiting list go; each appointment keeps
 * its date, doctor, facility, status and fee, and payments keep their amounts and receipts (with
 * the phone masked), so invoices, incentives and the pilot figures still add up and financial
 * records are kept for as long as the law requires. The audit log is left to its own purge.
 */
class PatientData
{
    /** Notes cancellations made by deletion carry */
    const CANCEL_NOTE = 'Cancelled because the patient asked for their data to be deleted.';

    // ---- Export ------------------------------------------------------------------------------

    /**
     * Everything held about the patient, in sections of rows, newest first.
     *
     * @return array ['generated_at' => string, 'account' => [...], 'sections' => [key => ['title' => string, 'rows' => array[]]]]
     */
    public static function export(User $user)
    {
        $id = (int) $user->id;
        $profile = (new Query())->from('{{%user_profile}}')->where(['user_id' => $id])->one();
        $gender = $profile && $profile['gender'] ? (new Query())->select('name')->from('{{%gender}}')->where(['id' => $profile['gender']])->scalar() : null;

        $account = [
            'account_number' => $id,
            'name' => $user->full_name,
            'username' => $user->username,
            'email' => $user->email ?: ($profile['email'] ?? null),
            'phone' => $user->phone_number ?: ($profile['phone'] ?? null),
            'date_of_birth' => $profile['dob'] ?? null,
            'gender' => $gender ?: null,
            'insurance_on_profile' => $profile['insurance'] ?? null,
            'signs_in_with' => $user->auth_provider ? ucfirst($user->auth_provider) : null,
            'account_created' => self::when($user->created_at),
            'account_status' => [User::STATUS_ACTIVE => 'active', User::STATUS_INACTIVE => 'email not verified', User::STATUS_DELETED => 'closed'][(int) $user->status] ?? (string) $user->status,
        ];

        $appointments = Appointments::find()->where(['patient_id' => $id])
            ->with(['consultant', 'facility'])->orderBy(['date' => SORT_DESC, 'time' => SORT_DESC])->all();
        $appointmentIds = array_map(function ($a) { return (int) $a->id; }, $appointments);
        $insurers = (new Query())->select(['provider', 'id'])->from('{{%provider}}')->indexBy('id')->column();

        $rows = [];
        foreach ($appointments as $a) {
            $rows[] = [
                'date' => $a->date,
                'time' => $a->time ? substr($a->time, 0, 5) : null,
                'minutes' => $a->duration_minutes ? (int) $a->duration_minutes : null,
                'doctor' => $a->consultant ? $a->consultant->displayName() : null,
                'facility' => $a->facility->name ?? null,
                'status' => $a->status ?: Appointments::STATUS_SCHEDULED,
                'booked' => self::when($a->created_at),
                'booked_through' => $a->booking_channel,
                'symptoms' => $a->symptoms_brief,
                'fee' => $a->fee !== null ? (float) $a->fee : null,
                'paying_by' => $a->payment_mode,
                'insurer' => $a->insurer_id ? ($insurers[$a->insurer_id] ?? null) : null,
                'member_number' => $a->member_number,
                'pre_authorisation' => $a->preauth_status,
                'pre_authorisation_reference' => $a->preauth_reference,
                'confirmed' => self::when($a->confirmed_at),
                'outcome_recorded' => self::when($a->outcome_at),
                'cancelled' => self::when($a->cancelled_at),
                'cancellation_note' => $a->cancellation_note,
            ];
        }
        $sections['appointments'] = ['title' => 'Appointments', 'rows' => $rows];

        $sections['messages'] = ['title' => 'Reminders and notices sent', 'rows' => $appointmentIds ? (new Query())
            ->select(['appointment_date' => 'a.date', 'channel' => 'n.notification_type', 'status' => 'n.status', 'due' => 'n.scheduled_time', 'sent' => 'n.sent_at'])
            ->from(['n' => '{{%appointment_notifications}}'])->innerJoin(['a' => '{{%appointments}}'], 'a.id = n.appointment_id')
            ->where(['n.appointment_id' => $appointmentIds])->orderBy(['n.scheduled_time' => SORT_DESC])->all() : []];

        $fees = (new Query())->from('{{%commitment_fee}}')->where(['patient_id' => $id])->orderBy(['id' => SORT_DESC])->all();
        $sections['fees'] = ['title' => 'Commitment fees', 'rows' => array_map(function ($f) {
            return [
                'date' => $f['fee_date'],
                'amount' => (int) $f['amount'],
                'paid_by' => $f['payer'],
                'state' => $f['state'],
                'mpesa_number' => $f['phone'],
                'paid' => self::when($f['paid_at']),
            ];
        }, $fees)];

        $credits = (new Query())->from('{{%patient_credit}}')->where(['patient_id' => $id])->orderBy(['id' => SORT_DESC])->all();
        $sections['credits'] = ['title' => 'Credits', 'rows' => array_map(function ($c) {
            return [
                'created' => self::when($c['created_at']),
                'amount' => (int) $c['amount'],
                'reason' => $c['reason'],
                'refundable' => (bool) $c['refundable'],
                'status' => $c['status'],
                'expires' => self::when($c['expires_at']),
                'refund_number' => $c['refund_phone'],
                'refunded' => self::when($c['refunded_at']),
            ];
        }, $credits)];

        $transactionIds = array_values(array_filter(array_merge(array_column($fees, 'mpesa_transaction_id'), array_column($credits, 'refund_mpesa_transaction_id'))));
        $sections['payments'] = ['title' => 'M-Pesa payments and refunds', 'rows' => $transactionIds ? array_map(function ($t) {
            return [
                'date' => self::when($t['created_at']),
                'kind' => $t['kind'] === 'b2c' ? 'refund' : 'payment',
                'amount' => (int) $t['amount'],
                'number' => $t['phone'],
                'status' => $t['status'],
                'receipt' => $t['receipt'],
            ];
        }, (new Query())->from('{{%mpesa_transaction}}')->where(['id' => $transactionIds])->orderBy(['id' => SORT_DESC])->all()) : []];

        $sections['waiting_list'] = ['title' => 'Waiting list', 'rows' => (new Query())
            ->select(['joined' => new Expression('FROM_UNIXTIME(w.created_at)'), 'doctor' => 'c.names', 'facility' => 'f.name', 'from' => 'w.from_date', 'to' => 'w.to_date', 'status' => 'w.status'])
            ->from(['w' => '{{%waitlist_entry}}'])
            ->leftJoin(['c' => '{{%consultant}}'], 'c.id = w.consultant_id')
            ->leftJoin(['f' => '{{%facility}}'], 'f.id = w.facility_id')
            ->where(['w.patient_id' => $id])->orderBy(['w.id' => SORT_DESC])->all()];

        $sections['ratings'] = ['title' => 'Ratings you gave', 'rows' => (new Query())
            ->select(['visit' => 'a.date', 'doctor' => 'c.names', 'stars' => 'r.stars', 'comment' => 'r.comment'])
            ->from(['r' => '{{%consultant_rating}}'])
            ->leftJoin(['a' => '{{%appointments}}'], 'a.id = r.appointment_id')
            ->leftJoin(['c' => '{{%consultant}}'], 'c.id = r.consultant_id')
            ->where(['r.patient_id' => $id])->orderBy(['r.id' => SORT_DESC])->all()];

        $sections['consents'] = ['title' => 'Consents', 'rows' => array_map(function (Consent $c) {
            return [
                'date' => self::when($c->created_at),
                'purpose' => Consent::purposes()[$c->purpose] ?? $c->purpose,
                'answer' => $c->granted ? 'agreed' : 'withdrawn',
                'how' => Consent::methods()[$c->method] ?? $c->method,
                'notice_version' => $c->notice_version,
            ];
        }, Consent::find()->where(['user_id' => $id])->orderBy(['id' => SORT_DESC])->all())];

        $sections['sign_ins'] = ['title' => 'Sign-ins (latest 100)', 'rows' => array_map(function ($l) {
            return ['date' => self::when($l['created_at']), 'how' => $l['note'], 'ip_address' => $l['ip']];
        }, (new Query())->from(AuditLog::tableName())
            ->where(['action' => AuditLog::ACTION_LOGIN, 'entity_type' => 'user', 'entity_id' => (string) $id])
            ->orderBy(['id' => SORT_DESC])->limit(100)->all())];

        $sections['requests'] = ['title' => 'Requests to close the account or delete data', 'rows' => array_map(function (AccountClosureRequest $r) {
            return [
                'asked' => self::when($r->created_at),
                'delete_data' => (bool) $r->delete_data,
                'reason' => $r->reason,
                'status' => $r->status,
            ];
        }, AccountClosureRequest::find()->where(['user_id' => $id])->orderBy(['id' => SORT_DESC])->all())];

        return [
            'generated_at' => date('Y-m-d H:i'),
            'controller' => Yii::$app->params['privacy.controllerName'] ?? '',
            'account' => $account,
            'sections' => $sections,
        ];
    }

    /** Records that the patient's data was exported, by them or by an admin. */
    public static function logExport(User $user, $note)
    {
        Audit::log(AuditLog::ACTION_DATA_EXPORTED, 'user', $user->id, [], $note);
    }

    // ---- Deletion ----------------------------------------------------------------------------

    /**
     * Patient asks for their data to be deleted: consent to processing is withdrawn (when consent
     * recording is on), upcoming appointments are cancelled and admins get a request marked
     * "delete data" under Closure Requests.
     */
    public static function requestDeletion(User $user, $reason = null)
    {
        if (Consent::isEnabled() && Consent::status($user->id) !== 'withdrawn') {
            Consent::record($user->id, Consent::PURPOSE_DATA, false, Consent::METHOD_ONLINE);
        }
        $request = AccountClosureRequest::open($user->id, $reason);
        if (!$request->delete_data) {
            $request->updateAttributes(['delete_data' => 1]);
        }
        self::cancelUpcoming($user->id);

        return $request;
    }

    /**
     * Cancels the patient's upcoming appointments, at the patient's request.
     *
     * @return int how many were cancelled
     */
    public static function cancelUpcoming($userId, $note = self::CANCEL_NOTE)
    {
        $upcoming = Appointments::find()
            ->where(['patient_id' => (int) $userId])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED, Appointments::STATUS_AWAITING_PAYMENT]]])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->all();
        $count = 0;
        foreach ($upcoming as $appointment) {
            if ($appointment->canBeCancelled() && $appointment->cancel($note, Appointments::REASON_PATIENT_REQUEST)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * What stops the data being deleted now: money the clinic still owes the patient.
     *
     * @return string[]
     */
    public static function deletionProblems(User $user)
    {
        $problems = [];
        if (!$user->isPatientAccount()) {
            return ['Only patient accounts can be deleted here.'];
        }
        $credits = PatientCredit::find()->where(['patient_id' => (int) $user->id])
            ->andWhere(['or',
                ['status' => PatientCredit::STATUS_REFUND_REQUESTED],
                ['status' => PatientCredit::STATUS_AVAILABLE, 'refundable' => 1],
            ])->all();
        foreach ($credits as $credit) {
            $problems[] = $credit->status === PatientCredit::STATUS_REFUND_REQUESTED
                ? "A refund of Ksh {$credit->amount} is waiting to be paid. Pay or decline it first."
                : "A refundable credit of Ksh {$credit->amount} is unused. Refund it (the patient asks from their credit link) before deleting.";
        }

        return $problems;
    }

    /**
     * Removes the patient's personal data and closes the account; see the class comment for what
     * is kept. Upcoming appointments are cancelled first. An open closure request is closed.
     *
     * @param string $why for the audit log, e.g. "at the patient's request" or "inactive for 3 years"
     * @return bool false when not a patient account (already anonymised counts as done)
     */
    public static function anonymise(User $user, $why)
    {
        if (!$user->isPatientAccount()) {
            return false;
        }
        if ($user->anonymised_at) {
            return true;
        }
        $id = (int) $user->id;
        self::cancelUpcoming($id);

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $phoneKey = $user->phone_key;
            $security = Yii::$app->security;
            // Direct updates: the audit behaviours would otherwise copy the old values into new entries
            User::updateAll([
                'username' => 'deleted-' . $id,
                'full_name' => 'Deleted patient #' . $id,
                'email' => null,
                'phone_number' => null,
                'phone_key' => null,
                'phone_verified_at' => null,
                'auth_provider' => null,
                'auth_client_id' => null,
                'staff_id' => null,
                'department' => null,
                'department_code' => null,
                'password_hash' => $security->generatePasswordHash($security->generateRandomString(32), 4),
                'auth_key' => $security->generateRandomString(),
                'password_reset_token' => null,
                'verification_token' => null,
                'status' => User::STATUS_DELETED,
                'anonymised_at' => time(),
                'updated_at' => time(),
            ], ['id' => $id]);

            $db->createCommand()->update('{{%user_profile}}', [
                'phone' => null, 'email' => null, 'dob' => null, 'gender' => null, 'insurance' => null,
                'device_token' => null, 'token_updated_at' => null,
            ], ['user_id' => $id])->execute();

            $appointmentIds = (new Query())->select('id')->from('{{%appointments}}')->where(['patient_id' => $id])->column();
            if ($appointmentIds) {
                $db->createCommand()->update('{{%appointments}}', [
                    'symptoms_brief' => null, 'member_number' => null, 'preauth_note' => null, 'location' => null,
                ], ['id' => $appointmentIds])->execute();
                $db->createCommand()->update('{{%booking_link}}', [
                    'recipient_name' => 'Deleted patient', 'recipient_phone' => null, 'recipient_email' => null,
                ], ['appointment_id' => $appointmentIds])->execute();
            }
            $db->createCommand()->update('{{%consultant_rating}}', ['comment' => null], ['patient_id' => $id])->execute();
            $db->createCommand()->update('{{%consent}}', ['ip' => null], ['user_id' => $id])->execute();
            $db->createCommand()->delete('{{%waitlist_entry}}', ['patient_id' => $id])->execute();
            $db->createCommand()->delete('{{%notification_schedules}}', ['user_id' => $id])->execute();
            if ($phoneKey) {
                $db->createCommand()->delete('{{%login_code}}', ['phone' => $phoneKey])->execute();
            }

            // Money records stay, with the number masked
            $transactionIds = [];
            foreach ((new Query())->select(['id', 'phone', 'mpesa_transaction_id'])->from('{{%commitment_fee}}')->where(['patient_id' => $id])->all() as $fee) {
                $db->createCommand()->update('{{%commitment_fee}}', ['phone' => self::mask($fee['phone'])], ['id' => $fee['id']])->execute();
                $transactionIds[] = $fee['mpesa_transaction_id'];
            }
            foreach ((new Query())->select(['id', 'refund_phone', 'refund_mpesa_transaction_id'])->from('{{%patient_credit}}')->where(['patient_id' => $id])->all() as $credit) {
                $db->createCommand()->update('{{%patient_credit}}', ['refund_phone' => self::mask($credit['refund_phone'])], ['id' => $credit['id']])->execute();
                $transactionIds[] = $credit['refund_mpesa_transaction_id'];
            }
            $transactionIds = array_values(array_filter($transactionIds));
            if ($transactionIds) {
                foreach ((new Query())->select(['id', 'phone'])->from('{{%mpesa_transaction}}')->where(['id' => $transactionIds])->all() as $t) {
                    // The raw callback names the payer: the amount, receipt and result are kept in their columns
                    $db->createCommand()->update('{{%mpesa_transaction}}', ['phone' => self::mask($t['phone']), 'callback' => null], ['id' => $t['id']])->execute();
                }
            }

            $now = time();
            $handler = Yii::$app->get('user', false);
            $db->createCommand()->update(AccountClosureRequest::tableName(), [
                'status' => AccountClosureRequest::STATUS_CLOSED,
                'handled_by' => $handler && !$handler->isGuest ? $handler->id : null,
                'handled_at' => $now,
                'handled_note' => mb_substr('Data deleted (' . $why . ').', 0, 500),
                'data_deleted_at' => $now,
            ], ['user_id' => $id, 'status' => AccountClosureRequest::STATUS_OPEN])->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Audit::log(AuditLog::ACTION_DATA_DELETED, 'user', $id, [], mb_substr('Personal data removed, ' . $why, 0, 255));
        $user->refresh();

        return true;
    }

    // ---- Retention rules (php yii retention/run) -----------------------------------------------

    /** Start of the inactivity period: accounts with nothing since then are anonymised. */
    public static function inactiveCutoff()
    {
        $years = max(1, (int) (Yii::$app->params['retention.inactiveYears'] ?? 3));

        return strtotime("-{$years} years");
    }

    /**
     * Patient accounts, not yet anonymised, created before the cutoff, with no sign-in and no
     * appointment (booked, or dated) since then.
     *
     * @return \yii\db\ActiveQuery of User
     */
    public static function inactivePatients()
    {
        $cutoff = self::inactiveCutoff();

        return User::find()->alias('u')
            ->where(['u.anonymised_at' => null])
            ->andWhere(['or', ['u.role' => User::ACCOUNT_PATIENT], ['u.role' => null], ['u.role' => '']])
            ->andWhere(['<', 'u.created_at', $cutoff])
            ->andWhere(['not exists', (new Query())->from(['a' => '{{%appointments}}'])
                ->where('a.patient_id = u.id')
                ->andWhere(['or', ['>=', 'a.date', date('Y-m-d', $cutoff)], ['>=', 'a.created_at', $cutoff]])])
            ->andWhere(['not exists', (new Query())->from(['l' => AuditLog::tableName()])
                ->where(['l.action' => AuditLog::ACTION_LOGIN, 'l.entity_type' => 'user'])
                ->andWhere('l.entity_id = CAST(u.id AS CHAR)')
                ->andWhere(['>=', 'l.created_at', $cutoff])])
            ->orderBy(['u.id' => SORT_ASC]);
    }

    /** Visits whose symptom notes are past keeping. */
    public static function oldSymptomsCondition()
    {
        $months = max(1, (int) (Yii::$app->params['retention.symptomMonths'] ?? 12));

        return ['and',
            ['not', ['symptoms_brief' => null]],
            ['<', 'date', date('Y-m-d', strtotime("-{$months} months"))],
        ];
    }

    /** @return int how many visits had their symptom notes cleared */
    public static function clearOldSymptoms()
    {
        $months = max(1, (int) (Yii::$app->params['retention.symptomMonths'] ?? 12));
        $count = Appointments::updateAll(['symptoms_brief' => null], self::oldSymptomsCondition());
        if ($count) {
            Audit::log(AuditLog::ACTION_PURGED, 'retention', null, [], "Symptom notes cleared on $count visits older than $months months");
        }

        return $count;
    }

    /**
     * Email sign-ups never verified, older than the limit, with no appointment.
     *
     * @return \yii\db\ActiveQuery of User
     */
    public static function staleSignups()
    {
        $days = max(1, (int) (Yii::$app->params['retention.unverifiedDays'] ?? 30));

        return User::find()->alias('u')
            ->where(['u.status' => User::STATUS_INACTIVE, 'u.anonymised_at' => null])
            ->andWhere(['or', ['u.role' => User::ACCOUNT_PATIENT], ['u.role' => null], ['u.role' => '']])
            ->andWhere(['<', 'u.created_at', time() - $days * 86400])
            ->andWhere(['not exists', (new Query())->from(['a' => '{{%appointments}}'])->where('a.patient_id = u.id')])
            ->orderBy(['u.id' => SORT_ASC]);
    }

    /**
     * Removes a never-verified sign-up altogether; anonymises it instead when another record still
     * points at it.
     */
    public static function removeSignup(User $user)
    {
        $id = (int) $user->id;
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()->delete('{{%user_profile}}', ['user_id' => $id])->execute();
            $db->createCommand()->delete('{{%notification_schedules}}', ['user_id' => $id])->execute();
            $db->createCommand()->delete('{{%auth_assignment}}', ['user_id' => (string) $id])->execute();
            $db->createCommand()->delete(User::tableName(), ['id' => $id])->execute();
            $transaction->commit();
        } catch (\yii\db\Exception $e) {
            $transaction->rollBack();
            return self::anonymise($user, 'email never verified');
        }

        return true;
    }

    // ---- Helpers -------------------------------------------------------------------------------

    private static function when($timestamp)
    {
        return $timestamp ? date('Y-m-d H:i', (int) $timestamp) : null;
    }

    /** A phone number with its middle hidden, kept within the column's width. */
    private static function mask($phone)
    {
        if ($phone === null || trim((string) $phone) === '') {
            return $phone;
        }
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) < 7) {
            return '***';
        }

        return substr($digits, 0, 4) . '***' . substr($digits, -3);
    }
}
