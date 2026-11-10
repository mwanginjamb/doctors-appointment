<?php

namespace frontend\tests\functional;

use frontend\components\FrontDesk;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class DashboardNumbersCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->book('10:00');

        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        (new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']))->save(false);
        $theirs = $this->newBooking('11:00', $this->otherClient);
        $theirs->consultant_id = $otherDoctor->id;
        $theirs->saveBooking();
    }

    public function adminDashboardShowsTheWholeClinic(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/settings');

        $I->see('Numbers for the whole clinic');
        $I->see('Next 7 days', '.stat-tile');
        $I->see('Dr. Test', '.busiest');
        $I->see('Dr. Other', '.busiest');
        $I->seeNumberOfElements('.per-day tbody tr', 7);
    }

    public function frontDeskSeesOnlyTheirConsultantsNumbers(FunctionalTester $I)
    {
        $frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        FrontDeskAssignment::replaceFor($frontDesk->id, [$this->consultant->id]);

        $I->amLoggedInAs($frontDesk);
        $I->amOnRoute('board/index');

        $I->see('Numbers for your consultants');
        $I->see('Dr. Test', '.busiest');
        $I->dontSee('Dr. Other', '.busiest');
    }

    public function frontDeskWithoutConsultantsGetsNoNumbers(FunctionalTester $I)
    {
        $frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);

        $I->amLoggedInAs($frontDesk);
        $I->amOnRoute('board/index');

        $I->dontSeeElement('.clinic-stats');
    }

    public function onlyAdminsSeeTheDashboard(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('site/settings');
        $I->seeResponseCodeIs(403);
    }
}
