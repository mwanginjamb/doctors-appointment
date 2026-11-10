<?php

namespace frontend\tests\unit;

use common\models\AccountClosureRequest;
use common\models\AuditLog;
use common\models\Consent;
use frontend\models\AppointmentNotifications;
use frontend\models\NotificationSchedules;
use frontend\models\RegisterPatientForm;
use frontend\services\NotificationService;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F11: what counts as consent, the opt-in rule with its legacy carry-over, and that WhatsApp/SMS
 * reminders follow it.
 */
class ConsentTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var \common\models\User a patient with nothing recorded, like those from before F11 */
    private $legacy;

    protected function _before()
    {
        $this->createParticipants();
        // createUser() records consent for patients; this one is created without it
        $this->legacy = $this->createUser('legacy.patient', 'consultant');
        $this->legacy->updateAttributes(['role' => 'client']);
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
    }

    public function testLegacyPatientsKeepRemindersUntilTheyAnswer()
    {
        $id = $this->legacy->id;
        $this->assertSame('none', Consent::status($id));
        $this->assertFalse(Consent::hasDataConsent($id));
        $this->assertTrue(Consent::remindersAllowed($id), 'legacy carry-over');

        Consent::record($id, Consent::PURPOSE_DATA, true, Consent::METHOD_ONLINE);
        Consent::record($id, Consent::PURPOSE_REMINDERS, false, Consent::METHOD_ONLINE);
        $this->assertFalse(Consent::remindersAllowed($id), 'they said no');

        Consent::record($id, Consent::PURPOSE_REMINDERS, true, Consent::METHOD_ONLINE);
        $this->assertTrue(Consent::remindersAllowed($id), 'and then yes');
    }

    public function testWithdrawingDataConsentStopsReminders()
    {
        $id = $this->client->id;
        $this->assertTrue(Consent::remindersAllowed($id));

        Consent::record($id, Consent::PURPOSE_DATA, false, Consent::METHOD_ONLINE);

        $this->assertFalse(Consent::remindersAllowed($id));
        $this->assertSame('withdrawn', Consent::status($id));
    }

    public function testANewNoticeVersionNeedsAcceptingAgain()
    {
        $id = $this->client->id;
        $this->assertTrue(Consent::hasCurrentDataConsent($id));

        $previous = Yii::$app->params['privacy.version'];
        Yii::$app->params['privacy.version'] = 'next-version';
        try {
            $this->assertFalse(Consent::hasCurrentDataConsent($id), 'the patient is asked again');
            $this->assertTrue(Consent::hasDataConsent($id), 'staff may still book them');
            $this->assertSame('old_notice', Consent::status($id));
        } finally {
            Yii::$app->params['privacy.version'] = $previous;
        }
    }

    public function testRecordsAreKeptAndAudited()
    {
        $consent = Consent::record($this->legacy->id, Consent::PURPOSE_DATA, true, Consent::METHOD_ONLINE);

        $consent->granted = false;
        $this->assertFalse($consent->save(false), 'the history is append-only');
        $this->assertFalse($consent->delete());

        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_CONSENT_GRANTED, 'entity_id' => (string) $this->legacy->id])->one();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Data processing', $entry->note);
    }

    public function testRecordIfChangedDoesNotRepeatAnAnswer()
    {
        $id = $this->client->id;
        $count = (int) Consent::find()->where(['user_id' => $id])->count();

        Consent::recordIfChanged($id, Consent::PURPOSE_DATA, true, Consent::METHOD_ONLINE);
        Consent::recordIfChanged($id, Consent::PURPOSE_REMINDERS, true, Consent::METHOD_ONLINE);
        $this->assertSame($count, (int) Consent::find()->where(['user_id' => $id])->count());

        Consent::recordIfChanged($id, Consent::PURPOSE_REMINDERS, false, Consent::METHOD_ONLINE);
        $this->assertSame($count + 1, (int) Consent::find()->where(['user_id' => $id])->count());
    }

    public function testWalkInsAreNotRegisteredWithoutConsent()
    {
        Yii::$app->user->setIdentity($this->consultantUser);

        $form = new RegisterPatientForm(['full_name' => 'Walk In', 'phone' => '0799000444', 'confirm_new' => true]);
        $this->assertNull($form->register());
        $this->assertArrayHasKey('consent', $form->errors);
        $this->assertArrayHasKey('reminders', $form->errors);

        $form = new RegisterPatientForm(['full_name' => 'Walk In', 'phone' => '0799000444', 'confirm_new' => true, 'consent' => true, 'reminders' => false]);
        $patient = $form->register();
        $this->assertNotNull($patient, json_encode($form->errors));

        $data = Consent::latest($patient->id, Consent::PURPOSE_DATA);
        $this->assertTrue((bool) $data->granted);
        $this->assertSame(Consent::METHOD_FRONT_DESK, $data->method);
        $this->assertSame((int) $this->consultantUser->id, (int) $data->recorded_by, 'who took the verbal consent');
        $this->assertFalse(Consent::remindersAllowed($patient->id));
    }

    public function testWhatsappRemindersFollowTheAnswer()
    {
        Consent::record($this->client->id, Consent::PURPOSE_REMINDERS, false, Consent::METHOD_ONLINE);
        $appointment = $this->book('10:00');
        AppointmentNotifications::updateAll(['scheduled_time' => date('Y-m-d H:i:s', strtotime('-1 minute'))], ['appointment_id' => $appointment->id]);

        NotificationService::processPendingNotifications();

        $notifications = AppointmentNotifications::find()->where(['appointment_id' => $appointment->id])->with('notificationSchedule')->all();
        $checked = 0;
        foreach ($notifications as $notification) {
            $toPatient = (int) $notification->notificationSchedule->user_id === (int) $this->client->id;
            if ($notification->notification_type === NotificationSchedules::TYPE_META) {
                $checked++;
                $this->assertSame(
                    $toPatient ? AppointmentNotifications::STATUS_CANCELLED : AppointmentNotifications::STATUS_QUEUED,
                    $notification->status,
                    $toPatient ? 'not to the patient who said no' : 'the consultant still gets theirs'
                );
            } elseif ($notification->notification_type === NotificationSchedules::TYPE_EMAIL) {
                $this->assertSame(AppointmentNotifications::STATUS_QUEUED, $notification->status, 'emails go out either way');
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testClosureRequestsOpenOnceAndAreWithdrawnByConsentingAgain()
    {
        $first = AccountClosureRequest::open($this->client->id, 'Moving away');
        $second = AccountClosureRequest::open($this->client->id);
        $this->assertSame($first->id, $second->id);

        $first->withdraw();
        $this->assertNull(AccountClosureRequest::openFor($this->client->id));
    }
}
