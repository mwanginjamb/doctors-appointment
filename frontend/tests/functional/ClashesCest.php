<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\AppointmentClash;
use frontend\models\Appointments;
use frontend\models\ConsultantFacility;
use frontend\models\ConsultantTimeOff;
use frontend\models\FacilityClosure;
use frontend\models\FrontDeskAssignment;
use frontend\models\PublicHoliday;
use frontend\services\Clashes;
use frontend\services\SelfServiceLink;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

/**
 * F14 part 1: bookings that clash with a facility closure, time off or a public holiday. Staff
 * see them on the clash list and tell the patients, who get their link to choose a new time
 * (inside the cut-off too); the day before, staff are reminded of those who have not moved.
 */
class ClashesCest
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;
    /** @var Appointments */
    private $appointment;
    /** @var int */
    private $mainId;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->mainId = (int) ConsultantFacility::findOne(['consultant_id' => $this->consultant->id])->facility_id;
        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        $this->frontDesk->updateAttributes(['facility_id' => $this->mainId]);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
        $this->appointment = $this->book('10:00');
    }

    public function _after(FunctionalTester $I)
    {
        FrontDesk::reset();
    }

    private function closure($date = null)
    {
        $closure = new FacilityClosure(['facility_id' => $this->mainId, 'date' => $date ?? $this->date, 'reason' => 'Staff training']);
        $closure->save(false);

        return $closure;
    }

    /** Emails queued that contain $contains (WhatsApp messages are counted apart) */
    private function queued($contains, $job = 'SendEmailMessageJob')
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', $job])->andWhere(['like', 'job', $contains])->count();
    }

    /** The patient's link, as a path the test browser can open */
    private function link()
    {
        return parse_url(SelfServiceLink::url(Appointments::findOne($this->appointment->id)), PHP_URL_PATH);
    }

    public function theFrontDeskSeesTheClashAndTellsThePatient(FunctionalTester $I)
    {
        $closure = $this->closure();
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnPage('/clash/closure/' . $closure->id);
        $I->see('closed', '#clash-source');
        $I->see('Client', '#clash-' . $this->appointment->id);
        $I->see('Not told', '#clash-' . $this->appointment->id);

        $I->submitForm('#clash-tell-form', ['message' => 'We are sorry for the change.']);
        $I->see('1 patient was told, with a link to choose a new time.');
        $I->see('not moved yet', '#clash-' . $this->appointment->id);
        $I->dontSeeElement('#clash-tell-form');
        $I->assertSame(1, $this->queued('Please choose a new time for your appointment with Dr. Test'));
        $I->assertSame(1, $this->queued('We are sorry for the change.'));
        $I->assertSame(1, $this->queued('Staff training'));
        $I->assertSame(1, $this->queued('Staff training', 'SendWhatsappTextJob'), 'WhatsApp too: the patient agreed to reminders');

        // Told once only
        $I->assertSame(0, Clashes::tell($closure));

        // The board says so
        $I->amOnRoute('/board/index', ['from' => $this->date, 'to' => $this->date]);
        $I->see('Needs a new time', '.needs-new-time');
    }

    public function timeOffDoesNotTellPatientsTheReason(FunctionalTester $I)
    {
        $timeOff = new ConsultantTimeOff(['consultant_id' => $this->consultant->id, 'start_at' => $this->date . ' 00:00:00',
            'end_at' => date('Y-m-d', strtotime($this->date . ' +1 day')) . ' 00:00:00', 'reason' => 'Family matter']);
        $timeOff->save(false);
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnPage('/clash/time_off/' . $timeOff->id);
        $I->see('Family matter', '#clash-source');
        $I->submitForm('#clash-tell-form', []);
        $I->assertSame(1, $this->queued('Dr. Test is not available at that time.'));
        $I->assertSame(0, $this->queued('Family matter'));
    }

    public function aHolidayClashIsListedForAdmins(FunctionalTester $I)
    {
        $holiday = new PublicHoliday(['date' => $this->date, 'name' => 'Test Day']);
        $holiday->save(false);
        $I->amLoggedInAs($this->admin);
        $I->amOnPage('/clash/holiday/' . $holiday->id);
        $I->see('Test Day', '#clash-source');
        $I->see('Client', '#clash-' . $this->appointment->id);
    }

    public function othersCannotSeeTheList(FunctionalTester $I)
    {
        $closure = $this->closure();
        $I->amLoggedInAs($this->client);
        $I->amOnPage('/clash/closure/' . $closure->id);
        $I->seeResponseCodeIs(404);

        $other = $this->createUser('desk.other', FrontDesk::ROLE);
        $I->amLoggedInAs($other);
        $I->amOnPage('/clash/closure/' . $closure->id);
        $I->seeResponseCodeIs(404);
    }

    public function aToldPatientMayMoveInsideTheCutOffButNotCancel(FunctionalTester $I)
    {
        // Six hours from now: inside the 24-hour cut-off
        $soon = time() + 6 * 3600;
        $tomorrow = date('Y-m-d', $soon);
        $this->appointment->updateAttributes(['date' => $tomorrow, 'time' => date('H:00:00', $soon)]);
        $I->amOnPage($this->link());
        $I->see('Changes are possible up to 24 hours before the appointment');
        $I->dontSeeElement('#clash-notice');

        $closure = $this->closure($tomorrow);
        $I->amLoggedInAs($this->frontDesk);
        $I->amOnPage('/clash/closure/' . $closure->id);
        $I->submitForm('#clash-tell-form', []);
        $I->amOnPage('/site/logout');

        $I->amOnPage($this->link());
        $I->see('This appointment cannot go ahead.', '#clash-notice');
        $I->see('Staff training', '#clash-notice');
        $I->dontSee('Changes are possible up to 24 hours before the appointment');
        $I->see('Choose another time');
        $I->dontSeeElement('#self-cancel-form');
    }

    public function staffAreRemindedTheDayBeforeOnce(FunctionalTester $I)
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->appointment->updateAttributes(['date' => $tomorrow, 'time' => '10:00:00']);
        $closure = $this->closure($tomorrow);
        (new AppointmentClash(['appointment_id' => $this->appointment->id, 'source_type' => Clashes::TYPE_CLOSURE,
            'source_id' => $closure->id, 'told_at' => time(), 'created_at' => time()]))->save(false);

        $I->assertSame(['Dr. Test: 1 still to move'], Clashes::remindStaff());
        $I->assertSame(1, $this->queued('front.desk@example.com'));
        $I->assertSame(1, $this->queued('doctor@example.com'));
        $I->assertSame(2, $this->queued('Patients still to move: 1 with Dr. Test'), 'the consultant and their front desk');
        $I->assertSame([], Clashes::remindStaff(), 'once');

        // Moved away from the closure: no longer needs a new time
        $this->appointment->updateAttributes(['date' => date('Y-m-d', strtotime('+2 days'))]);
        $I->assertFalse(Clashes::mustMove(Appointments::findOne($this->appointment->id)));
    }
}
