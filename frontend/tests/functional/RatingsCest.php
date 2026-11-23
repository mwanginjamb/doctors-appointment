<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\services\Ratings;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use Yii;

/**
 * F30 on the pages: the patient rates from their link (no sign-in); the consultant reads their
 * ratings without names; the public profile shows the average from 5 ratings.
 */
class RatingsCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    /** A visit an hour ago, recorded as attended by the consultant. */
    private function attendedVisit($time = '10:00')
    {
        $appointment = $this->book($time);
        $past = time() - 3600;
        $appointment->updateAttributes(['date' => date('Y-m-d', $past), 'time' => date('H:i:00', $past)]);
        $appointment = Appointments::findOne($appointment->id);
        Yii::$app->user->login($this->consultantUser);
        $appointment->recordOutcome(Appointments::STATUS_COMPLETED);
        Yii::$app->user->logout();

        return Appointments::findOne($appointment->id);
    }

    private function link(Appointments $appointment)
    {
        return '/rate/' . $appointment->id . '/' . Ratings::signature($appointment);
    }

    public function thePatientRatesFromTheLinkWithoutSigningIn(FunctionalTester $I)
    {
        $appointment = $this->attendedVisit();
        $I->amOnPage($this->link($appointment));
        $I->seeResponseCodeIs(200);
        $I->see('How was your visit to Dr. Test?');

        $I->submitForm('#rate-form', ['stars' => '', 'comment' => '']);
        $I->see('Choose from 1 to 5 stars.', '#rate-error');

        $I->submitForm('#rate-form', ['stars' => '4', 'comment' => 'Listened well; a short wait.']);
        $I->see('Thank you for rating your visit.');
        $I->see('You rated this visit 4 out of 5.', '#rate-given');
    }

    public function aWrongLinkIsRefused(FunctionalTester $I)
    {
        $appointment = $this->attendedVisit();
        $I->amOnPage('/rate/' . $appointment->id . '/' . str_repeat('0', 32));
        $I->seeResponseCodeIs(404);
    }

    public function theConsultantSeesRatingsButNotWhoGaveThem(FunctionalTester $I)
    {
        Ratings::rate($this->attendedVisit(), '5', 'Very thorough.');
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('/ratings/index');
        $I->see('5.0', '#ratings-average');
        $I->see('Very thorough.', '#ratings-list');
        $I->dontSee('Client', '#ratings-list');

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('/ratings/index');
        $I->seeResponseCodeIs(403);
    }

    public function theProfileShowsTheAverageFromFiveRatings(FunctionalTester $I)
    {
        foreach (['09:00', '09:30', '10:00', '10:30'] as $t) {
            Ratings::rate($this->attendedVisit($t), '4', '');
        }
        $I->amOnPage($this->consultant->profileUrl());
        $I->dontSeeElement('#doctor-rating');

        Ratings::rate($this->attendedVisit('11:00'), '5', '');
        $I->amOnPage($this->consultant->profileUrl());
        $I->see('4.2', '#doctor-rating');
        $I->see('from 5 patient ratings', '#doctor-rating');
    }
}
