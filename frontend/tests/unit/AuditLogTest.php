<?php

namespace frontend\tests\unit;

use common\components\Audit;
use common\models\AuditLog;
use console\controllers\AuditController;
use frontend\models\Appointments;
use frontend\models\ConsultantWorkingHours;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * T7: what the audit log records, as whom, and what it never keeps.
 */
class AuditLogTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    protected function _before()
    {
        $this->createParticipants();
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
        Audit::reset();
    }

    /** The newest entry about a record. */
    private function last($entityType, $entityId, $action = null)
    {
        return AuditLog::find()
            ->where(['entity_type' => $entityType, 'entity_id' => (string) $entityId])
            ->andFilterWhere(['action' => $action])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    public function testBookingIsRecordedWithTheActorAndWithoutTheSymptoms()
    {
        Yii::$app->user->setIdentity($this->client);
        $appointment = $this->book('10:00');

        $entry = $this->last('appointment', $appointment->id, AuditLog::ACTION_CREATED);
        $this->assertNotNull($entry);
        $this->assertSame(AuditLog::ACTOR_PATIENT, $entry->actor_type);
        $this->assertSame((int) $this->client->id, (int) $entry->actor_id);
        $this->assertSame('Client', $entry->actor_name);

        $changes = $entry->getChangeList();
        $this->assertSame([null, '10:00:00'], $changes['time']);
        $this->assertSame([null, AuditLog::MASKED], $changes['symptoms_brief'], 'health details are not copied into the log');
        $this->assertArrayNotHasKey('created_at', $changes);
        $this->assertStringNotContainsString('Headache', $entry->changes);
    }

    public function testMovingRecordsOldAndNewTimes()
    {
        $appointment = $this->book('10:00');
        Yii::$app->user->setIdentity($this->consultantUser);

        $appointment->time = '11:00';
        $this->assertTrue($appointment->saveBooking(), json_encode($appointment->errors));

        $entry = $this->last('appointment', $appointment->id, AuditLog::ACTION_UPDATED);
        $this->assertSame(['10:00:00', '11:00:00'], $entry->getChangeList()['time']);
        $this->assertSame(AuditLog::ACTOR_STAFF, $entry->actor_type);
        $this->assertSame('Doctor', $entry->actor_name);
    }

    public function testSavingWithoutChangesRecordsNothing()
    {
        $appointment = $this->book('10:00');
        $count = (int) AuditLog::find()->where(['entity_type' => 'appointment', 'entity_id' => (string) $appointment->id])->count();

        $appointment->refresh();
        $appointment->save(false);

        $this->assertSame($count, (int) AuditLog::find()->where(['entity_type' => 'appointment', 'entity_id' => (string) $appointment->id])->count());
    }

    public function testCancellingRecordsStatusAndNote()
    {
        $appointment = $this->book('10:00');
        $appointment->cancel('Patient called');

        $changes = $this->last('appointment', $appointment->id, AuditLog::ACTION_UPDATED)->getChangeList();
        $this->assertSame(Appointments::STATUS_CANCELLED, $changes['status'][1]);
        $this->assertSame('Patient called', $changes['cancellation_note'][1]);
    }

    public function testPasswordChangeIsRecordedWithoutTheHashAndKeysNeverAre()
    {
        $user = $this->client;
        $oldHash = $user->password_hash;
        $user->setPassword('another_password_1');
        $user->generateAuthKey();
        $user->email = 'new.address@example.com';
        $user->save(false);

        $entry = $this->last('user', $user->id, AuditLog::ACTION_UPDATED);
        $changes = $entry->getChangeList();
        $this->assertSame([AuditLog::MASKED, AuditLog::MASKED], $changes['password_hash']);
        $this->assertSame(['client@example.com', 'new.address@example.com'], $changes['email']);
        $this->assertArrayNotHasKey('auth_key', $changes);
        $this->assertStringNotContainsString($oldHash, $entry->changes);
        $this->assertStringNotContainsString($user->password_hash, $entry->changes);
        $this->assertStringNotContainsString($user->auth_key, $entry->changes);
    }

    public function testEntriesCannotBeChangedOrDeleted()
    {
        Audit::log(AuditLog::ACTION_UPDATED, 'appointment', 1, ['status' => ['scheduled', 'confirmed']]);
        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();

        $entry->note = 'tampered';
        $this->assertFalse($entry->save(false));
        $this->assertFalse($entry->delete());
        $this->assertNull(AuditLog::findOne($entry->id)->note);
    }

    public function testWeeklyHoursChangesAreRecordedAsSummaries()
    {
        Yii::$app->user->setIdentity($this->admin);
        $week = $this->consultant->getWeek();
        $week[6] = []; // close Saturdays

        ConsultantWorkingHours::replaceWeek($this->consultant->id, $week);

        $entry = $this->last('consultant', $this->consultant->id, AuditLog::ACTION_HOURS_CHANGED);
        $this->assertNotNull($entry);
        [$old, $new] = $entry->getChangeList()['hours'];
        $this->assertStringContainsString('Sat', $old);
        $this->assertStringNotContainsString('Sat', $new);

        $count = (int) AuditLog::find()->where(['action' => AuditLog::ACTION_HOURS_CHANGED])->count();
        ConsultantWorkingHours::replaceWeek($this->consultant->id, $week);
        $this->assertSame($count, (int) AuditLog::find()->where(['action' => AuditLog::ACTION_HOURS_CHANGED])->count(), 'the same week again changes nothing');
    }

    public function testFrontDeskAssignmentsAreRecordedByName()
    {
        $desk = $this->createUser('front.desk', 'front_desk');

        FrontDeskAssignment::replaceFor($desk->id, [$this->consultant->id]);

        $changes = $this->last('user', $desk->id, AuditLog::ACTION_ASSIGNMENTS_CHANGED)->getChangeList();
        $this->assertSame([null, 'Dr. Test'], $changes['consultants']);
    }

    public function testActingThroughALinkOnlyAppliesToThatRequest()
    {
        Audit::actAs(AuditLog::ACTOR_SELF_SERVICE, $this->client->id, 'Client');
        $this->assertSame([AuditLog::ACTOR_SELF_SERVICE, $this->client->id, 'Client'], Audit::currentActor());

        $app = Yii::$app;
        Yii::$app = clone $app; // the next request gets a new application
        try {
            $this->assertNotSame(AuditLog::ACTOR_SELF_SERVICE, Audit::currentActor()[0]);
        } finally {
            Yii::$app = $app;
        }
    }

    public function testPurgeRemovesOnlyEntriesPastRetention()
    {
        $old = new AuditLog(['created_at' => strtotime('-8 years'), 'actor_type' => AuditLog::ACTOR_SYSTEM, 'action' => AuditLog::ACTION_LOGIN]);
        $old->save(false);
        $recent = new AuditLog(['created_at' => strtotime('-6 years'), 'actor_type' => AuditLog::ACTOR_SYSTEM, 'action' => AuditLog::ACTION_LOGIN]);
        $recent->save(false);

        $command = new AuditController('audit', Yii::$app);
        ob_start();
        $command->actionPurge();
        ob_end_clean();

        $this->assertNull(AuditLog::findOne($old->id));
        $this->assertNotNull(AuditLog::findOne($recent->id));
        $purged = AuditLog::find()->where(['action' => AuditLog::ACTION_PURGED])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($purged, 'the purge itself is logged');
        $this->assertStringContainsString('older than 7 years', $purged->note);
    }
}
