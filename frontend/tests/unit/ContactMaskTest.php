<?php

namespace frontend\tests\unit;

use common\helpers\Contact;
use common\models\User;
use Yii;

/**
 * F21: patients' phone numbers and emails, partly hidden outside the appointment page.
 */
class ContactMaskTest extends \Codeception\Test\Unit
{
    protected function _after()
    {
        Yii::$app->user->logout();
    }

    public function testKenyanNumbersShowTheirStartAndEnd()
    {
        foreach (['0712345678', '+254 712 345 678', '254712345678', '712345678'] as $number) {
            $this->assertSame('0712***678', Contact::phone($number), $number);
        }
        $this->assertSame('0110***456', Contact::phone('0110 123 456'));
    }

    public function testOtherNumbersKeepTheirCountryCode()
    {
        $this->assertSame('+4479***456', Contact::phone('+44 7911 123456'));
        $this->assertSame('***', Contact::phone('12345'));
    }

    public function testEmailsShowTheirFirstLetterAndDomain()
    {
        $this->assertSame('j***@mail.com', Contact::email('jane.doe@mail.com'));
        $this->assertSame('***', Contact::email('not-an-email'));
    }

    public function testEmptyValuesStayEmpty()
    {
        $this->assertNull(Contact::phone(null));
        $this->assertNull(Contact::phone('  '));
        $this->assertNull(Contact::email(''));
        $this->assertSame('0712***678', Contact::summary('0712345678', null));
        $this->assertSame('0712***678 · j***@mail.com', Contact::summary('0712345678', 'jane@mail.com'));
    }

    public function testTheOwnerSeesTheirOwnInFull()
    {
        $user = new User(['id' => 4242, 'username' => 'owner']);
        Yii::$app->user->login($user);

        $this->assertSame('0712345678', Contact::phone('0712345678', 4242));
        $this->assertSame('jane@mail.com', Contact::email('jane@mail.com', 4242));
        $this->assertSame('0712***678', Contact::phone('0712345678', 99));
    }
}
