<?php

namespace frontend\tests\functional;

use common\models\MpesaTransaction;
use frontend\tests\FunctionalTester;
use yii\helpers\Json;

/**
 * T11: Safaricom's callbacks reach the app without a session or CSRF token, and only with our token
 * in the address. (The settling rules themselves are covered by MpesaTest.)
 */
class MpesaCallbackCest
{
    /** common/config/test.php */
    const TOKEN = 'test-callback-token-0123456789';

    /** @var MpesaTransaction */
    private $transaction;

    public function _before(FunctionalTester $I)
    {
        MpesaTransaction::deleteAll();
        $this->transaction = new MpesaTransaction([
            'kind' => MpesaTransaction::KIND_STK,
            'phone' => '254712345678',
            'amount' => 250,
            'reference' => 'HealthFirst',
            'description' => 'Commitment fee',
            'checkout_request_id' => 'ws_CO_cest',
        ]);
        $this->transaction->save(false);
    }

    private function cancelledCallback()
    {
        return Json::encode(['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'm', 'CheckoutRequestID' => 'ws_CO_cest', 'ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user',
        ]]]);
    }

    public function aCallbackWithOurTokenIsAccepted(FunctionalTester $I)
    {
        [$status, $body] = $I->sendServerPost('/index-test.php/mpesa/stk/' . self::TOKEN, $this->cancelledCallback());

        $I->assertSame(200, $status);
        $I->assertSame(['ResultCode' => 0, 'ResultDesc' => 'Accepted'], Json::decode($body));
        $this->transaction->refresh();
        $I->assertSame(MpesaTransaction::STATUS_CANCELLED, $this->transaction->status);
    }

    public function aWrongTokenIsNotFound(FunctionalTester $I)
    {
        [$status] = $I->sendServerPost('/index-test.php/mpesa/stk/wrong-token-0123456789abc', $this->cancelledCallback());

        $I->assertSame(404, $status);
        $this->transaction->refresh();
        $I->assertSame(MpesaTransaction::STATUS_PENDING, $this->transaction->status);
    }

    public function anUnreadableBodyIsStillAcknowledged(FunctionalTester $I)
    {
        [$status, $body] = $I->sendServerPost('/index-test.php/mpesa/b2c-result/' . self::TOKEN, 'not json');

        $I->assertSame(200, $status);
        $I->assertSame(0, Json::decode($body)['ResultCode']);
    }

    public function onlyPostIsAllowed(FunctionalTester $I)
    {
        $I->amOnPage('/index-test.php/mpesa/stk/' . self::TOKEN);
        $I->dontSeeResponseCodeIs(200);
    }
}
