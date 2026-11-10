<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\services\SelfServiceLink;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

/**
 * F3: patients reschedule or cancel from the signed link in their messages, up to 24 hours before.
 */
class SelfServiceCest
{
    use BookingDataTrait;

    /** @var string an open day at least 2 days ahead (Mon-Sat) */
    private $day;
    /** @var Appointments */
    private $appointment;

    public function _before(FunctionalTester $I)
    {
        \Yii::$app->cache->flush(); // per-IP rate limit
        $this->createParticipants();
        $ts = strtotime('+2 days');
        if (date('N', $ts) === '7') {
            $ts = strtotime('+1 day', $ts);
        }
        $this->day = date('Y-m-d', $ts);
        $this->appointment = $this->newBooking('10:00', null, $this->day);
        $this->appointment->saveBooking();
    }

    private function open(FunctionalTester $I, Appointments $appointment, $sig = null)
    {
        $I->amOnRoute('manage/index', ['id' => $appointment->id, 'sig' => $sig ?? SelfServiceLink::signature($appointment)]);
    }

    private function queued($class, array $contains)
    {
        $query = (new Query())->from('{{%queue}}')->where(['like', 'job', $class]);
        foreach ($contains as $text) {
            $query->andWhere(['like', 'job', $text]);
        }

        return (int) $query->count();
    }

    public function theLinkShowsTheAppointmentAndFreeTimes(FunctionalTester $I)
    {
        $this->open($I, $this->appointment);

        $I->see('Your appointment');
        $I->see('Dr. Test');
        $I->see('Choose another time');
        $I->seeInSource('"time":"10:30"');
        $I->dontSeeInSource('"date":"' . $this->day . '","time":"10:00"'); // its own slot
        $I->seeElement('#self-cancel-form');
    }

    public function aTamperedOrOutdatedLinkIsRefused(FunctionalTester $I)
    {
        $this->open($I, $this->appointment, str_repeat('a', 32));
        $I->see('This link is no longer valid');

        $oldSig = SelfServiceLink::signature($this->appointment);
        Appointments::updateAll(['time' => '11:00:00'], ['id' => $this->appointment->id]);
        $this->open($I, $this->appointment, $oldSig);
        $I->see('This link is no longer valid');
    }

    public function thePatientCancelsAndTheConsultantIsTold(FunctionalTester $I)
    {
        $before = $this->queued('SendEmailMessageJob', ['doctor@example.com']);
        $this->open($I, $this->appointment);
        $I->submitForm('#self-cancel-form', ['reason' => 'Travelling that week']);

        $I->see('Your appointment is cancelled.');
        $saved = Appointments::findOne($this->appointment->id);
        $I->assertSame(Appointments::STATUS_CANCELLED, $saved->status);
        $I->assertStringContainsString('Travelling that week', $saved->cancellation_note);
        $I->assertSame($before + 1, $this->queued('SendEmailMessageJob', ['doctor@example.com', 'cancelled by Client']));
        $I->assertSame(0, $this->queued('SendEmailMessageJob', ['client@example.com', 'is cancelled']), 'the patient did it; they are not told');
    }

    public function thePatientReschedulesAndTheConsultantIsTold(FunctionalTester $I)
    {
        $this->open($I, $this->appointment);
        $I->submitForm('#reschedule-form', ['date' => $this->day, 'time' => '14:00']);

        $I->see('Your appointment is moved.');
        $saved = Appointments::findOne($this->appointment->id);
        $I->assertSame('14:00:00', $saved->time);
        $I->assertSame(30, (int) $saved->duration_minutes);
        $I->assertSame(1, $this->queued('SendEmailMessageJob', ['doctor@example.com', 'moved by Client']));
        // Redirected to the moved appointment's new link
        $I->seeInCurrentUrl('/manage/' . $saved->id . '/' . SelfServiceLink::signature($saved));
    }

    public function onlyOfferedTimesAreAccepted(FunctionalTester $I)
    {
        $this->newBooking('11:00', $this->otherClient, $this->day)->saveBooking(); // busy on the same day
        $this->open($I, $this->appointment);
        $I->submitForm('#reschedule-form', ['date' => $this->day, 'time' => '11:00']);

        $I->see('That time is no longer free.');
        $I->assertSame('10:00:00', Appointments::findOne($this->appointment->id)->time);
    }

    public function changesStopTwentyFourHoursBefore(FunctionalTester $I)
    {
        $soon = (int) ceil((time() + 5 * 3600) / 300) * 300;
        Appointments::updateAll(['date' => date('Y-m-d', $soon), 'time' => date('H:i:00', $soon)], ['id' => $this->appointment->id]);
        $appointment = Appointments::findOne($this->appointment->id);

        $this->open($I, $appointment);
        $I->see('Changes are possible up to 24 hours before the appointment.');
        $I->dontSeeElement('#self-cancel-form');
        $I->assertNull(SelfServiceLink::urlIfChangeable($appointment), 'no link in messages inside the cut-off');
    }

    public function theMovedNoticeCarriesTheLink(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $moved = $I->sendJsonPost(['/api/update-visit'], ['id' => $this->appointment->id, 'date' => $this->day, 'time' => '14:00:00']);
        $I->assertSame('success', $moved['status'], json_encode($moved));

        $saved = Appointments::findOne($this->appointment->id);
        $I->assertSame(1, $this->queued('SendWhatsappTextJob', ['0712345678', '/manage/' . $saved->id . '/' . SelfServiceLink::signature($saved)]));
    }
}
