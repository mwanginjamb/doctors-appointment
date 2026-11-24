<?php

namespace frontend\tests\unit;

use common\models\AccountClosureRequest;
use common\models\AuditLog;
use common\models\Consent;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\ConsultantRating;
use frontend\models\PatientCredit;
use frontend\models\UserProfile;
use frontend\services\PatientData;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * F12: a patient's data exported and deleted (anonymised), and the retention rules.
 */
class PatientDataTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    protected function _before()
    {
        $this->createParticipants();
    }

    protected function _after()
    {
        Yii::$app->user->logout();
    }

    /** A visit that took place $daysAgo days ago, attended. */
    private function pastVisit($daysAgo = 1, $patient = null)
    {
        $appointment = $this->book('10:00', $patient);
        $appointment->updateAttributes([
            'date' => date('Y-m-d', strtotime("-$daysAgo days")),
            'status' => Appointments::STATUS_COMPLETED,
            'outcome_at' => time() - $daysAgo * 86400,
            'member_number' => 'MEM-123',
            'created_at' => time() - $daysAgo * 86400,
        ]);

        return Appointments::findOne($appointment->id);
    }

    public function testTheExportHoldsTheAccountAppointmentsAndConsents()
    {
        $this->pastVisit();
        $data = PatientData::export($this->client);

        $this->assertSame('client@example.com', $data['account']['email']);
        $this->assertSame('1990-01-01', $data['account']['date_of_birth']);
        $this->assertCount(1, $data['sections']['appointments']['rows']);
        $visit = $data['sections']['appointments']['rows'][0];
        $this->assertSame('Dr. Test', $visit['doctor']);
        $this->assertSame('Headache', $visit['symptoms']);
        $this->assertSame('MEM-123', $visit['member_number']);
        $this->assertCount(2, $data['sections']['consents']['rows'], 'data processing and reminders');
        $this->assertNotFalse(json_encode($data), 'exportable as JSON');

        // Nothing of another patient's
        $this->book('11:00', $this->otherClient);
        $this->assertCount(1, PatientData::export($this->client)['sections']['appointments']['rows']);
    }

    public function testAskingForDeletionWithdrawsConsentCancelsAndOpensARequest()
    {
        $upcoming = $this->book('10:00');
        Yii::$app->user->login($this->client);
        $request = PatientData::requestDeletion($this->client, 'Moving abroad');

        $this->assertSame(1, (int) $request->delete_data);
        $this->assertSame('Moving abroad', $request->reason);
        $this->assertSame('withdrawn', Consent::status($this->client->id));
        $this->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($upcoming->id)->status);
        $this->assertSame(1, (int) Appointments::findOne($upcoming->id)->cancelled_at_patient_request);
    }

    public function testDeletingRemovesPersonalDataAndKeepsTheBareFacts()
    {
        $past = $this->pastVisit(10);
        $upcoming = $this->book('11:00');
        (new ConsultantRating(['appointment_id' => $past->id, 'consultant_id' => $this->consultant->id, 'patient_id' => $this->client->id, 'stars' => 4, 'comment' => 'Kind']))->save(false);
        Yii::$app->db->createCommand()->insert('{{%waitlist_entry}}', [
            'patient_id' => $this->client->id, 'consultant_id' => $this->consultant->id, 'facility_id' => $past->facility_id,
            'from_date' => $this->date, 'to_date' => $this->date, 'status' => 'waiting', 'created_at' => time(),
        ])->execute();
        $request = AccountClosureRequest::open($this->client->id, 'Please delete');

        Yii::$app->user->login($this->admin);
        $this->assertTrue(PatientData::anonymise($this->client, "at the patient's request"));

        $user = User::findOne($this->client->id);
        $this->assertSame('Deleted patient #' . $user->id, $user->full_name);
        $this->assertSame('deleted-' . $user->id, $user->username);
        $this->assertNull($user->email);
        $this->assertNull($user->phone_number);
        $this->assertSame(User::STATUS_DELETED, (int) $user->status);
        $this->assertNotNull($user->anonymised_at);
        $this->assertNull(User::findByUsername('client'), 'cannot sign in');
        $this->assertFalse($user->validatePassword('password_0'));

        $profile = UserProfile::findOne(['user_id' => $user->id]);
        $this->assertNull($profile->dob);
        $this->assertNull($profile->phone);

        $visit = Appointments::findOne($past->id);
        $this->assertNull($visit->symptoms_brief);
        $this->assertNull($visit->member_number);
        $this->assertSame(Appointments::STATUS_COMPLETED, $visit->status, 'the visit itself stays');
        $this->assertSame((int) $this->consultantUser->id, (int) $visit->consultant_id);
        $this->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($upcoming->id)->status);

        $rating = ConsultantRating::findOne(['appointment_id' => $past->id]);
        $this->assertNull($rating->comment);
        $this->assertSame(4, (int) $rating->stars, 'the stars still count in the average');
        $this->assertSame(0, (int) (new Query())->from('{{%waitlist_entry}}')->where(['patient_id' => $user->id])->count());

        $request = AccountClosureRequest::findOne($request->id);
        $this->assertSame(AccountClosureRequest::STATUS_CLOSED, $request->status);
        $this->assertNotNull($request->data_deleted_at);
        $this->assertSame((int) $this->admin->id, (int) $request->handled_by);

        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_DATA_DELETED, 'entity_id' => (string) $user->id])->one();
        $this->assertNotNull($entry);
        $this->assertNull($entry->changes, 'no old values copied into the log');

        // Again: nothing to do
        $this->assertTrue(PatientData::anonymise($user, 'again'));
    }

    public function testMoneyStillOwedBlocksDeletion()
    {
        $credit = new PatientCredit([
            'patient_id' => $this->client->id, 'source_fee_id' => 999999, 'amount' => 300, 'reason' => PatientCredit::REASON_CLINIC_CANCELLED,
            'refundable' => 1, 'status' => PatientCredit::STATUS_AVAILABLE, 'expires_at' => time() + 86400,
        ]);
        $credit->save(false);
        $this->assertCount(1, PatientData::deletionProblems($this->client));

        $credit->updateAttributes(['refundable' => 0]);
        $this->assertSame([], PatientData::deletionProblems($this->client), 'a credit that cannot be cashed out does not');

        $this->assertNotEmpty(PatientData::deletionProblems($this->consultantUser), 'staff accounts are not deleted here');
        $this->assertFalse(PatientData::anonymise($this->consultantUser, 'test'));
    }

    public function testInactivePatientsAreFoundAfterThreeYears()
    {
        $old = time() - 4 * 365 * 86400;
        foreach ([$this->client, $this->otherClient] as $user) {
            $user->updateAttributes(['created_at' => $old]);
        }
        $this->pastVisit(4 * 365, $this->client);
        $this->consultantUser->updateAttributes(['created_at' => $old]);

        $ids = PatientData::inactivePatients()->select('u.id')->column();
        $this->assertContains((int) $this->client->id, array_map('intval', $ids));
        $this->assertContains((int) $this->otherClient->id, array_map('intval', $ids));
        $this->assertNotContains((int) $this->consultantUser->id, array_map('intval', $ids), 'staff are not');

        // A recent sign-in or a recent visit keeps the account
        Yii::$app->db->createCommand()->insert(AuditLog::tableName(), [
            'created_at' => time() - 86400, 'actor_type' => AuditLog::ACTOR_PATIENT, 'action' => AuditLog::ACTION_LOGIN,
            'entity_type' => 'user', 'entity_id' => (string) $this->client->id,
        ])->execute();
        $this->pastVisit(30, $this->otherClient);
        $ids = array_map('intval', PatientData::inactivePatients()->select('u.id')->column());
        $this->assertNotContains((int) $this->client->id, $ids);
        $this->assertNotContains((int) $this->otherClient->id, $ids);
    }

    public function testSymptomNotesAreClearedAfterTwelveMonths()
    {
        $old = $this->pastVisit(400);
        $recent = $this->pastVisit(30, $this->otherClient);

        $this->assertSame(1, PatientData::clearOldSymptoms());
        $this->assertNull(Appointments::findOne($old->id)->symptoms_brief);
        $this->assertSame('Headache', Appointments::findOne($recent->id)->symptoms_brief);
    }

    public function testUnverifiedSignUpsAreRemovedAfter30Days()
    {
        $stale = $this->createUser('never.verified', 'client');
        $stale->updateAttributes(['status' => User::STATUS_INACTIVE, 'created_at' => time() - 31 * 86400]);
        $fresh = $this->createUser('just.signed.up', 'client');
        $fresh->updateAttributes(['status' => User::STATUS_INACTIVE]);

        $ids = array_map('intval', PatientData::staleSignups()->select('u.id')->column());
        $this->assertSame([(int) $stale->id], $ids);

        $this->assertTrue(PatientData::removeSignup(User::findOne($stale->id)));
        $this->assertNull(User::findOne($stale->id));
        $this->assertSame(0, (int) Consent::find()->where(['user_id' => $stale->id])->count());
        $this->assertNotNull(User::findOne($fresh->id));
    }
}
