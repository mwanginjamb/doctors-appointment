<?php

namespace frontend\tests\unit;

use frontend\models\Appointments;
use frontend\models\ConsultantRating;
use frontend\services\Ratings;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * F30: the rating link after an attended visit, rating 1–5, withdrawal on a no-show, and when the
 * average goes public.
 */
class RatingsTest extends \Codeception\Test\Unit
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

    /** A visit that took place an hour ago, booked through the usual path. */
    private function pastVisit($time = '10:00')
    {
        $appointment = $this->book($time);
        $past = time() - 3600;
        $appointment->updateAttributes(['date' => date('Y-m-d', $past), 'time' => date('H:i:00', $past)]);

        return Appointments::findOne($appointment->id);
    }

    private function attended(Appointments $appointment, $outcome = Appointments::STATUS_COMPLETED)
    {
        Yii::$app->user->login($this->consultantUser);
        $this->assertTrue($appointment->recordOutcome($outcome));

        return Appointments::findOne($appointment->id);
    }

    private function queued($contains)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])->andWhere(['like', 'job', $contains])->count();
    }

    public function testAnAttendedVisitSendsTheLinkOnceLater()
    {
        $appointment = $this->attended($this->pastVisit());
        $this->assertNotNull($appointment->rating_requested_at);
        $this->assertSame(1, $this->queued('How was your visit to Dr. Test?'));
        $this->assertSame(1, $this->queued('/rate/' . $appointment->id . '/' . Ratings::signature($appointment)));
        $delayed = (new Query())->from('{{%queue}}')->where(['like', 'job', 'How was your visit'])->andWhere(['>', 'delay', 0])->count();
        $this->assertGreaterThan(0, (int) $delayed, 'sent about 3 hours later');

        // Corrected and re-recorded: not sent again
        $this->attended($appointment, Appointments::STATUS_NO_SHOW);
        $this->attended(Appointments::findOne($appointment->id));
        $this->assertSame(1, $this->queued('How was your visit to Dr. Test?'));
    }

    public function testANoShowSendsNothing()
    {
        $appointment = $this->attended($this->pastVisit(), Appointments::STATUS_NO_SHOW);
        $this->assertNull($appointment->rating_requested_at);
        $this->assertSame(0, $this->queued('How was your visit'));
        $this->assertFalse(Ratings::isOpen($appointment));
    }

    public function testRatingFromOneToFiveAndChangingIt()
    {
        $appointment = $this->attended($this->pastVisit());
        $this->assertTrue(Ratings::isOpen($appointment));
        $this->assertTrue(Ratings::rate($appointment, '0', '')->hasErrors('stars'));
        $this->assertTrue(Ratings::rate($appointment, '6', '')->hasErrors('stars'));
        $this->assertFalse(Ratings::rate($appointment, '4', ' Kind and clear. ')->hasErrors());
        $this->assertFalse(Ratings::rate($appointment, '5', 'Kind and clear.')->hasErrors());

        $ratings = ConsultantRating::find()->where(['appointment_id' => $appointment->id])->all();
        $this->assertCount(1, $ratings, 'one rating per visit');
        $this->assertSame(5, (int) $ratings[0]->stars);
        $this->assertSame((int) $this->consultant->id, (int) $ratings[0]->consultant_id);
    }

    public function testANoShowCorrectionWithdrawsTheRating()
    {
        $appointment = $this->attended($this->pastVisit());
        Ratings::rate($appointment, '2', '');
        $this->assertSame(1, Ratings::summary($this->consultant)['count']);
        $this->attended($appointment, Appointments::STATUS_NO_SHOW);
        $this->assertSame(0, Ratings::summary($this->consultant)['count']);
        $this->assertFalse(Ratings::isOpen(Appointments::findOne($appointment->id)));
    }

    public function testTheLinkClosesAfter30Days()
    {
        $appointment = $this->attended($this->pastVisit());
        $appointment->updateAttributes(['outcome_at' => time() - 31 * 86400]);
        $this->assertFalse(Ratings::isOpen($appointment));
    }

    public function testTheAverageGoesPublicFromFiveRatings()
    {
        $stars = [5, 4, 4, 3];
        foreach ($stars as $i => $n) {
            Ratings::rate($this->attended($this->pastVisit()), (string) $n, '');
        }
        $this->assertNull(Ratings::publicSummary($this->consultant), 'four is not enough');
        $summary = Ratings::summary($this->consultant);
        $this->assertSame(4, $summary['count']);
        $this->assertEqualsWithDelta(4.0, $summary['average'], 0.001);

        Ratings::rate($this->attended($this->pastVisit()), '5', '');
        $public = Ratings::publicSummary($this->consultant);
        $this->assertNotNull($public);
        $this->assertEqualsWithDelta(4.2, $public['average'], 0.001);
        $this->assertSame(2, $public['stars'][4]);
    }
}
