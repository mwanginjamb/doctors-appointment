<?php

namespace frontend\tests\unit;

use common\models\Consent;
use frontend\models\Appointments;
use frontend\models\WaitlistEntry;
use frontend\models\WaitlistForm;
use frontend\services\Waitlist;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * F4 waiting list: who is told when a time frees up, the first to book wins, "something earlier"
 * moves the appointment, and when entries end.
 */
class WaitlistTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var \common\models\User */
    private $third;

    protected function _before()
    {
        $this->createParticipants();
        $this->third = $this->createUser('third.patient', 'client');
        // Tests run at any hour: no quiet hours unless a test sets them
        Yii::$app->params['waitlist.quietFrom'] = 0;
        Yii::$app->params['waitlist.quietUntil'] = 0;
        Waitlist::$now = null;
    }

    protected function _after()
    {
        Yii::$app->params['waitlist.quietFrom'] = 21;
        Yii::$app->params['waitlist.quietUntil'] = 7;
        Waitlist::$now = null;
    }

    private function facilityId()
    {
        return (int) $this->consultant->engagements[0]->facility_id;
    }

    private function join($patient, array $attributes = [])
    {
        return Waitlist::join($attributes + [
            'patient_id' => $patient->id,
            'consultant_id' => $this->consultant->id,
            'facility_id' => $this->facilityId(),
            'from_date' => $this->date,
            'to_date' => $this->date,
            'day_start' => null,
            'day_end' => null,
            'appointment_id' => null,
        ], $patient->id);
    }

    private function queued($class, array $contains = [])
    {
        $query = (new Query())->from('{{%queue}}')->where(['like', 'job', $class]);
        foreach ($contains as $text) {
            $query->andWhere(['like', 'job', $text]);
        }

        return (int) $query->count();
    }

    public function testACancellationTellsEveryoneWaitingOnce()
    {
        $taken = $this->book('10:00', $this->third);
        $first = $this->join($this->client);
        $second = $this->join($this->otherClient);
        $this->assertNotContains($this->date . ' 10:00', $first->getOfferedSlots(), 'taken when they joined');
        $this->assertSame(0, Waitlist::notifyFor($this->consultantUser->id), 'nothing new yet');

        $jobs = $this->queued('WaitlistJob');
        $this->assertTrue($taken->cancel('Travelling'));
        $this->assertSame($jobs + 1, $this->queued('WaitlistJob'), 'the freed slot is checked by the queue');

        $emails = $this->queued('SendEmailMessageJob', ['A time has opened']);
        $this->assertSame(2, Waitlist::notifyFor($this->consultantUser->id));
        $this->assertSame($emails + 2, $this->queued('SendEmailMessageJob', ['A time has opened']));
        $this->assertSame(1, $this->queued('SendEmailMessageJob', ['other.client@example.com', 'A time has opened']));
        $this->assertSame(1, $this->queued('SendWhatsappTextJob', ['/waitlist/' . $second->id . '/' . $second->signature()]));

        $this->assertSame(0, Waitlist::notifyFor($this->consultantUser->id), 'each time is announced once');
        $this->assertSame(1, (int) WaitlistEntry::findOne($first->id)->notices_sent);
    }

    public function testTheFirstToBookGetsTheTime()
    {
        $taken = $this->book('10:00', $this->third);
        $first = $this->join($this->client);
        $second = $this->join($this->otherClient);
        $taken->cancel();

        [$appointment, $error] = Waitlist::book($first, $this->date, '10:00');
        $this->assertNull($error);
        $this->assertSame((int) $this->client->id, (int) $appointment->patient_id);
        $this->assertSame((int) $this->client->id, (int) Appointments::findOne($appointment->id)->created_by);
        $first->refresh();
        $this->assertSame(WaitlistEntry::STATUS_BOOKED, $first->status);
        $this->assertSame((int) $appointment->id, (int) $first->booked_appointment_id);

        [$none, $error] = Waitlist::book($second, $this->date, '10:00');
        $this->assertNull($none);
        $this->assertStringContainsString('just been taken', $error);
        $this->assertTrue(WaitlistEntry::findOne($second->id)->isWaiting(), 'still waiting for the next one');

        [, $error] = Waitlist::book($first, $this->date, '10:30');
        $this->assertStringContainsString('no longer on this waiting list', $error, 'one booking per entry');
    }

    public function testSomethingEarlierMovesTheAppointment()
    {
        $later = $this->book('15:00');
        $blocking = $this->book('10:00', $this->third);
        $form = new WaitlistForm(['appointment' => $later, 'patient' => $this->client]);
        $this->assertTrue($form->save(), json_encode($form->errors));
        $entry = $form->entry;
        $this->assertSame((int) $later->id, (int) $entry->appointment_id);
        $this->assertStringStartsWith('Earlier than', $entry->getWindowLabel());
        foreach (Waitlist::flatten($entry->openSlots(time())) as $slot) {
            $this->assertLessThan($this->date . ' 15:00', $slot, 'only earlier times');
        }

        $blocking->cancel();
        $this->assertSame(1, Waitlist::notifyFor($this->consultantUser->id));
        $this->assertSame(1, $this->queued('SendEmailMessageJob', ['An earlier time with']));

        $this->join($this->third); // waits for any time that day
        $jobs = $this->queued('WaitlistJob');
        [$moved, $error] = Waitlist::book($entry, $this->date, '10:00');
        $this->assertNull($error);
        $this->assertSame((int) $later->id, (int) $moved->id, 'moved, not booked twice');
        $this->assertSame('10:00:00', Appointments::findOne($later->id)->time);
        $this->assertSame(1, (int) Appointments::find()->where(['patient_id' => $this->client->id])->andWhere(['<>', 'status', Appointments::STATUS_CANCELLED])->count());
        $this->assertSame(WaitlistEntry::STATUS_BOOKED, WaitlistEntry::findOne($entry->id)->status);
        $this->assertSame($jobs + 1, $this->queued('WaitlistJob'), 'its old time frees up in turn');
        $this->assertSame(1, Waitlist::notifyFor($this->consultantUser->id), 'and goes to the next patient waiting');
        $this->assertSame(1, $this->queued('SendEmailMessageJob', ['third.patient@example.com', '15:00']));
    }

    public function testEntriesEndWhenTheyNoLongerMakeSense()
    {
        // Booked some other way, inside the window
        $wait = $this->join($this->client);
        $this->book('11:00');
        $this->assertSame(WaitlistEntry::STATUS_BOOKED, WaitlistEntry::findOne($wait->id)->status);

        // The appointment to move was cancelled
        $later = $this->book('16:00', $this->otherClient);
        $form = new WaitlistForm(['appointment' => $later, 'patient' => $this->otherClient]);
        $this->assertTrue($form->save());
        $later->cancel();
        $this->assertSame(WaitlistEntry::STATUS_EXPIRED, WaitlistEntry::findOne($form->entry->id)->status);

        // The window passed: the sweep ends it
        $old = $this->join($this->third, ['from_date' => date('Y-m-d', strtotime('-3 days')), 'to_date' => date('Y-m-d', strtotime('-1 day'))]);
        Waitlist::sweep();
        $this->assertSame(WaitlistEntry::STATUS_EXPIRED, WaitlistEntry::findOne($old->id)->status);
    }

    public function testJoiningTwiceUpdatesTheSameEntry()
    {
        $first = $this->join($this->client);
        $again = $this->join($this->client, ['day_start' => '00:00:00', 'day_end' => '12:00:00']);
        $this->assertSame($first->id, $again->id);
        $this->assertStringStartsWith('Mornings', $again->getWindowLabel());
    }

    public function testQuietHoursAndTheDailyLimit()
    {
        Yii::$app->params['waitlist.quietFrom'] = 21;
        Yii::$app->params['waitlist.quietUntil'] = 7;
        $this->assertTrue(Waitlist::isQuietTime(strtotime('today 22:30')));
        $this->assertTrue(Waitlist::isQuietTime(strtotime('today 06:59')));
        $this->assertFalse(Waitlist::isQuietTime(strtotime('today 07:00')));
        $this->assertFalse(Waitlist::isQuietTime(strtotime('today 13:00')));
        Yii::$app->params['waitlist.quietFrom'] = 0;
        Yii::$app->params['waitlist.quietUntil'] = 0;

        $taken = $this->book('10:00', $this->third);
        $entry = $this->join($this->client);
        $entry->updateAttributes(['notices_on' => date('Y-m-d'), 'notices_that_day' => 3]);
        $taken->cancel();
        $this->assertSame(0, Waitlist::notifyFor($this->consultantUser->id), 'three a day at most');

        $entry->updateAttributes(['notices_on' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertSame(1, Waitlist::notifyFor($this->consultantUser->id), 'a new day');
    }

    public function testWhatsappOnlyWithConsent()
    {
        Consent::record($this->otherClient->id, Consent::PURPOSE_REMINDERS, false, Consent::METHOD_ONLINE);
        $taken = $this->book('10:00', $this->third);
        $entry = $this->join($this->otherClient);
        $taken->cancel();

        Waitlist::notifyFor($this->consultantUser->id);
        $this->assertSame(1, $this->queued('SendEmailMessageJob', ['other.client@example.com']));
        $this->assertSame(0, $this->queued('SendWhatsappTextJob', ['/waitlist/' . $entry->id . '/']));
    }

    public function testTheLinkIsSigned()
    {
        $entry = $this->join($this->client);
        $this->assertNotNull(WaitlistEntry::verify($entry->id, $entry->signature()));
        $this->assertNull(WaitlistEntry::verify($entry->id, str_repeat('a', 32)));
        $this->assertNull(WaitlistEntry::verify($entry->id, null));

        $other = $this->join($this->otherClient);
        $this->assertNull(WaitlistEntry::verify($other->id, $entry->signature()), "another patient's signature");
    }
}
