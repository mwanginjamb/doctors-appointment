<?php

namespace backend\tests\functional;

use backend\tests\FunctionalTester;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantRating;
use Yii;

/**
 * F30 in the admin area: each consultant's ratings, with who gave them.
 */
class RatingsAdminCest
{
    private function user($username, $role)
    {
        $user = new User(['username' => $username, 'email' => "$username@example.com", 'full_name' => ucwords(str_replace('.', ' ', $username)),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
        $user->setPassword('password_0');
        $user->generateAuthKey();
        $user->save(false);

        return $user;
    }

    public function anAdminSeesRatingsWithThePatient(FunctionalTester $I)
    {
        $admin = $this->user('platform.admin', 'admin');
        $doctor = $this->user('dr.rated', 'consultant');
        $patient = $this->user('mary.patient', 'client');
        $consultant = new Consultant(['user_id' => $doctor->id, 'names' => 'Dr. Rated']);
        $consultant->save(false);
        Yii::$app->db->createCommand()->insert(Appointments::tableName(), [
            'date' => date('Y-m-d', strtotime('-2 days')), 'time' => '10:00:00', 'patient_id' => $patient->id,
            'consultant_id' => $doctor->id, 'status' => Appointments::STATUS_COMPLETED, 'created_at' => time(),
        ])->execute();
        (new ConsultantRating(['appointment_id' => (int) Yii::$app->db->getLastInsertID(), 'consultant_id' => $consultant->id,
            'patient_id' => $patient->id, 'stars' => 3, 'comment' => 'Long wait.', 'withdrawn' => 0]))->save(false);

        $I->amLoggedInAs($admin);
        $I->amOnRoute('/rating/index');
        $I->see('Dr. Rated', '#ratings-by-consultant');
        $I->see('3.0', '#ratings-by-consultant');
        $I->see('Not yet', '#ratings-by-consultant');
        $I->see('Mary Patient', '#ratings-grid');
        $I->see('Long wait.', '#ratings-grid');
    }
}
