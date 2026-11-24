<?php

namespace frontend\tests\functional;

use common\models\AccountClosureRequest;
use common\models\AuditLog;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\PatientCredit;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * F12: My data (see, download, ask to delete) and the admins' side on Closure Requests.
 */
class MyDataCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        \Yii::$app->cache->flush();
        $this->createParticipants();
    }

    public function patientsSeeAndDownloadTheirData(FunctionalTester $I)
    {
        $this->book('10:00');
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('site/index');
        $I->seeElement('#menu-my-data');

        $I->amOnRoute('my-data/index');
        $I->see('My data', 'h1');
        $I->click('#data-view');
        $I->see('Your data', 'h1');
        $I->see('client@example.com', '#data-account');
        $I->see('Headache', '#data-appointments');
        $I->see('Dr. Test', '#data-appointments');

        $I->amOnRoute('my-data/download');
        $I->seeResponseCodeIs(200);
        $json = json_decode($I->grabPageSource(), true);
        $I->assertSame('client@example.com', $json['account']['email']);
        $I->assertSame('Headache', $json['sections']['appointments']['rows'][0]['symptoms']);

        $I->assertSame(2, (int) AuditLog::find()->where(['action' => AuditLog::ACTION_DATA_EXPORTED, 'entity_id' => (string) $this->client->id])->count());
    }

    public function staffHaveNoMyDataPage(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('my-data/index');
        $I->seeResponseCodeIs(403);
    }

    public function patientsAskForTheirDataToBeDeleted(FunctionalTester $I)
    {
        $appointment = $this->book('10:00');
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('my-data/index');

        $I->submitForm('#delete-data-form', ['reason' => 'No longer in Nairobi']);
        $I->see('Tick the box to confirm');
        $I->assertNull(AccountClosureRequest::openFor($this->client->id));

        $I->submitForm('#delete-data-form', ['reason' => 'No longer in Nairobi', 'confirm' => '1']);
        $I->see('Your request is sent.');
        $I->seeElement('#deletion-pending');
        $I->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($appointment->id)->status);
        $I->assertSame(1, (int) AccountClosureRequest::openFor($this->client->id)->delete_data);

        // Held on the consent page elsewhere, but My data still opens
        $I->amOnRoute('appointments/index');
        $I->seeInCurrentUrl('consent');
        $I->amOnRoute('my-data/view');
        $I->see('Your data', 'h1');
    }

    public function adminsSeeTheDataAndDeleteIt(FunctionalTester $I)
    {
        $this->book('10:00');
        $request = AccountClosureRequest::open($this->client->id, 'Please delete');
        $request->updateAttributes(['delete_data' => 1]);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('closure-request/index');
        $I->see('Delete data', '.badge');
        $I->see('Please delete');

        $I->amOnRoute('closure-request/data', ['id' => $request->id]);
        $I->see('Data held about Client', 'h1');
        $I->see('Headache', '#data-appointments');

        $I->amOnRoute('closure-request/index');
        $I->submitForm('.closure-delete-form', []);
        $I->see("Client's data is deleted");
        $user = User::findOne($this->client->id);
        $I->assertNotNull($user->anonymised_at);
        $I->assertSame('Deleted patient #' . $user->id, $user->full_name);
        $request = AccountClosureRequest::findOne($request->id);
        $I->assertSame(AccountClosureRequest::STATUS_CLOSED, $request->status);
        $I->assertNotNull($request->data_deleted_at);
    }

    public function deletingWaitsForMoneyTheClinicOwes(FunctionalTester $I)
    {
        (new PatientCredit([
            'patient_id' => $this->client->id, 'source_fee_id' => 999999, 'amount' => 300,
            'reason' => PatientCredit::REASON_CLINIC_CANCELLED, 'refundable' => 1,
            'status' => PatientCredit::STATUS_AVAILABLE, 'expires_at' => time() + 86400,
        ]))->save(false);
        $request = AccountClosureRequest::open($this->client->id);

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('my-data/index');
        $I->seeElement('#deletion-money');

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('closure-request/index');
        $I->submitForm('.closure-delete-form', []);
        $I->see('Not deleted: A refundable credit of Ksh 300 is unused.');
        $I->assertNull(User::findOne($this->client->id)->anonymised_at);
        $I->assertSame(AccountClosureRequest::STATUS_OPEN, AccountClosureRequest::findOne($request->id)->status);
    }
}
