<?php

namespace frontend\tests\unit;

use common\components\PhoneMessenger;
use common\helpers\Phone;
use common\models\LoginCode;
use common\models\User;
use frontend\services\PhoneSignIn;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * Patients sign in with their phone number and a one-time code: number formats, the codes, the
 * rate limits, which accounts a proven number may sign in to, and new accounts.
 */
class PhoneSignInTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    const NUMBER = '254700111222';

    protected function _before()
    {
        // Unit tests run without a PHP session (output has started): keep session data in memory
        Yii::$app->set('session', new class extends \yii\web\Session {
            private $data = [];

            public function open() {}
            public function getIsActive() { return true; }
            public function get($key, $default = null) { return $this->data[$key] ?? $default; }
            public function set($key, $value) { $this->data[$key] = $value; }
            public function remove($key) { $value = $this->data[$key] ?? null; unset($this->data[$key]); return $value; }
            public function has($key) { return isset($this->data[$key]); }
        });
        $messenger = Yii::$app->get('phoneMessenger');
        $messenger->failing = [];
        $messenger->fakeChannels = null;
        PhoneMessenger::$outbox = [];
        LoginCode::deleteAll();
        Yii::$app->params['phoneSignIn'] = ['enabled' => true];
    }

    protected function _after()
    {
        Yii::$app->user->setIdentity(null);
    }

    private function codeSent()
    {
        preg_match('/^(\d{6}) /', PhoneMessenger::last()['text'], $m);

        return $m[1];
    }

    private function patient($username, array $attributes = [])
    {
        $user = $this->createUser($username, 'client');
        foreach ($attributes as $name => $value) {
            $user->$name = $value;
        }
        $user->save(false);

        return User::findOne($user->id);
    }

    // ---- Phone numbers ---------------------------------------------------------------------------

    public function testNumbersAreReadInEveryKenyanForm()
    {
        foreach (['0712 345 678', '0712345678', '+254 712 345 678', '254712345678', '712345678', '+254-712-345-678', '00254712345678'] as $raw) {
            $this->assertSame('254712345678', Phone::normalize($raw), $raw);
        }
        $this->assertSame('254110123456', Phone::normalize('0110 123 456'));
        $this->assertSame('447911123456', Phone::normalize('+44 7911 123456'));
        foreach (['', '12345', '07123', '+254 712 345', '0712 345 678 9', 'not a phone'] as $raw) {
            $this->assertNull(Phone::normalize($raw), $raw);
        }
        $this->assertSame('+254 7•• ••• 678', Phone::mask('254712345678'));
    }

    public function testAccountsKeepTheNumberAsTypedAndAKeyToFindThem()
    {
        $user = $this->patient('typed.number', ['phone_number' => '0722 000 111']);
        $this->assertSame('0722 000 111', $user->phone_number);
        $this->assertSame('254722000111', $user->phone_key);

        // A changed number is no longer proven
        $user->updateAttributes(['phone_verified_at' => time()]);
        $user->phone_number = '0733 000 111';
        $user->save(false);
        $user->refresh();
        $this->assertSame('254733000111', $user->phone_key);
        $this->assertNull($user->phone_verified_at);
    }

    // ---- Codes -----------------------------------------------------------------------------------

    public function testACodeIsSentByWhatsAppAndWorksOnce()
    {
        $result = PhoneSignIn::requestCode('0700 111 222', null, '10.0.0.1');
        $this->assertSame(['status' => 'sent', 'phone' => self::NUMBER, 'channel' => 'whatsapp'], $result);
        $this->assertSame(self::NUMBER, PhoneMessenger::last()['phone']);
        $this->assertStringContainsString('is your sign-in code', PhoneMessenger::last()['text']);
        $code = $this->codeSent();
        $this->assertNotContains($code, LoginCode::find()->select('code_hash')->column(), 'only a hash is stored');

        $this->assertSame('ok', PhoneSignIn::verifyCode(self::NUMBER, $code));
        $this->assertSame(self::NUMBER, PhoneSignIn::provenPhone());
        $this->assertSame('expired', LoginCode::check(self::NUMBER, $code), 'used once');
    }

    public function testSmsWhenWhatsAppCannotSend()
    {
        Yii::$app->get('phoneMessenger')->failing = ['whatsapp'];
        $result = PhoneSignIn::requestCode('0700111222', null, '10.0.0.1');
        $this->assertSame('sent', $result['status']);
        $this->assertSame('sms', $result['channel']);
        $this->assertSame(1, (int) LoginCode::find()->count(), 'the unsent WhatsApp code is not kept');

        // Asked for by SMS directly ("send by SMS instead")
        Yii::$app->get('phoneMessenger')->failing = [];
        $this->assertSame('sms', PhoneSignIn::requestCode('0700111222', 'sms', '10.0.0.1')['channel']);

        // Nothing can send
        Yii::$app->get('phoneMessenger')->failing = ['whatsapp', 'sms'];
        $this->assertSame('failed', PhoneSignIn::requestCode('0700111222', null, '10.0.0.1')['status']);
    }

    public function testUnavailableWithoutAChannelOrWhenSwitchedOff()
    {
        Yii::$app->get('phoneMessenger')->fakeChannels = [];
        $this->assertFalse(PhoneSignIn::isAvailable());
        $this->assertSame('unavailable', PhoneSignIn::requestCode('0700111222')['status']);

        Yii::$app->get('phoneMessenger')->fakeChannels = null;
        Yii::$app->params['phoneSignIn']['enabled'] = false;
        $this->assertFalse(PhoneSignIn::isAvailable());
    }

    public function testAWrongCodeCountsAndFiveEndIt()
    {
        PhoneSignIn::requestCode('0700111222');
        $code = $this->codeSent();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame('wrong', PhoneSignIn::verifyCode(self::NUMBER, $wrong), "try $i");
        }
        $this->assertSame('expired', PhoneSignIn::verifyCode(self::NUMBER, $wrong), 'the fifth wrong try ends the code');
        $this->assertSame('expired', PhoneSignIn::verifyCode(self::NUMBER, $code), 'even the right code no longer works');
        $this->assertNull(PhoneSignIn::provenPhone());
    }

    public function testCodesExpireAndANewOneCancelsTheOld()
    {
        PhoneSignIn::requestCode('0700111222');
        $first = $this->codeSent();
        LoginCode::updateAll(['expires_at' => time() - 1]);
        $this->assertSame('expired', PhoneSignIn::verifyCode(self::NUMBER, $first));

        LoginCode::deleteAll();
        PhoneSignIn::requestCode('0700111222');
        $old = $this->codeSent();
        PhoneSignIn::requestCode('0700111222');
        $new = $this->codeSent();
        if ($old !== $new) {
            $this->assertSame('wrong', PhoneSignIn::verifyCode(self::NUMBER, $old), 'only the latest code counts');
        }
        $this->assertSame('ok', PhoneSignIn::verifyCode(self::NUMBER, $new));
    }

    public function testRateLimitsPerNumberAndPerAddress()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('sent', PhoneSignIn::requestCode('0700111222', null, '10.0.0.1')['status']);
        }
        $this->assertSame('limited', PhoneSignIn::requestCode('0700111222', null, '10.0.0.2')['status'], '3 per number in 15 minutes');

        // 10 per number a day
        LoginCode::deleteAll();
        $this->insertCodes(self::NUMBER, null, 10, time() - 3600);
        $this->assertSame('limited', PhoneSignIn::requestCode('0700111222')['status']);

        // 10 per address an hour, whatever the numbers
        LoginCode::deleteAll();
        $this->insertCodes('254700999888', '10.0.0.9', 10, time() - 1800);
        $this->assertSame('limited', PhoneSignIn::requestCode('0700111222', null, '10.0.0.9')['status']);
        $this->assertSame('sent', PhoneSignIn::requestCode('0700111222', null, '10.0.0.8')['status']);
    }

    private function insertCodes($phone, $ip, $count, $at)
    {
        for ($i = 0; $i < $count; $i++) {
            Yii::$app->db->createCommand()->insert('{{%login_code}}', [
                'phone' => $phone, 'code_hash' => str_repeat('0', 64), 'channel' => 'whatsapp',
                'expires_at' => $at + 600, 'used_at' => $at, 'ip' => $ip, 'created_at' => $at,
            ])->execute();
        }
    }

    // ---- Accounts on the number ------------------------------------------------------------------

    public function testOnlyPatientAccountsTheNumberMayReachAreOffered()
    {
        $walkIn = $this->patient('walk.in', ['phone_number' => '0700111222', 'email' => null]);
        $proven = $this->patient('proven.patient', ['phone_number' => '+254700111222']);
        $proven->updateAttributes(['phone_verified_at' => time()]);
        $selfRegistered = $this->patient('self.registered', ['phone_number' => '0700 111 222']);
        $staff = $this->createUser('front.desk', 'front_desk');
        $staff->phone_number = '0700111222';
        $staff->email = null;
        $staff->save(false);
        $gone = $this->patient('gone.patient', ['phone_number' => '0700111222', 'email' => null, 'status' => User::STATUS_DELETED]);
        $this->patient('other.number', ['phone_number' => '0700999999', 'email' => null]);

        $ids = array_map(function ($u) { return (int) $u->id; }, PhoneSignIn::accountsFor(self::NUMBER));
        sort($ids);
        $expected = [(int) $walkIn->id, (int) $proven->id];
        sort($expected);
        $this->assertSame($expected, $ids, 'walk-ins (number entered by the clinic) and proven numbers; never staff, deleted or self-registered unproven');
        $this->assertSame(1, PhoneSignIn::unlinkedCount(self::NUMBER), 'the self-registered account is linked by signing in with email');
        $this->assertNotContains((int) $selfRegistered->id, $ids);
        $this->assertNotContains((int) $staff->id, $ids);
        $this->assertNotContains((int) $gone->id, $ids);
    }

    public function testSigningInToAFamilyAccount()
    {
        $mary = $this->patient('mary.wanjiku', ['full_name' => 'Mary Wanjiku', 'phone_number' => '0700111222', 'email' => null]);
        $john = $this->patient('john.kamau', ['full_name' => 'John Kamau', 'phone_number' => '0700111222', 'email' => null]);
        $outsider = $this->patient('outsider', ['phone_number' => '0700999999', 'email' => null]);

        $this->assertSame('Mary W.', PhoneSignIn::shortName($mary));
        $this->assertFalse(PhoneSignIn::signIn(self::NUMBER, $outsider->id), 'only accounts on the number');
        $this->assertTrue(Yii::$app->user->isGuest);

        $this->assertTrue(PhoneSignIn::signIn(self::NUMBER, $john->id));
        $this->assertSame((int) $john->id, (int) Yii::$app->user->id);
        $this->assertNotNull(User::findOne($john->id)->phone_verified_at);
        $this->assertNull(PhoneSignIn::provenPhone(), 'used up once signed in');

        $entry = \common\models\AuditLog::find()->where(['action' => 'login', 'entity_id' => (string) $john->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame('via phone', $entry->note);
    }

    public function testANewNumberCreatesAPatientAccount()
    {
        $user = PhoneSignIn::createAccount(self::NUMBER, '  Grace   Achieng ');
        $this->assertNotNull($user);
        $this->assertSame('Grace Achieng', $user->full_name);
        $this->assertSame(User::ACCOUNT_PATIENT, $user->role);
        $this->assertNull($user->email);
        $this->assertSame(self::NUMBER, $user->phone_key);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(User::STATUS_ACTIVE, (int) $user->status);
        $this->assertSame((int) $user->id, (int) Yii::$app->user->id);
        $this->assertArrayHasKey('patient', Yii::$app->authManager->getRolesByUser($user->id));

        // Next time the number finds it
        $this->assertSame([(int) $user->id], array_map(function ($u) { return (int) $u->id; }, PhoneSignIn::accountsFor(self::NUMBER)));
        $this->assertNull(PhoneSignIn::createAccount(self::NUMBER, '   '), 'a name is needed');
    }

    public function testSigningInWithEmailWhileTheNumberIsProvenLinksIt()
    {
        $user = $this->patient('linked.later', ['phone_number' => '0700 111 222']);
        $this->assertSame([], PhoneSignIn::accountsFor(self::NUMBER));

        PhoneSignIn::requestCode('0700111222');
        PhoneSignIn::verifyCode(self::NUMBER, $this->codeSent());
        Yii::$app->user->login($user);

        $this->assertNotNull(User::findOne($user->id)->phone_verified_at);
        Yii::$app->user->setIdentity(null);
        $this->assertCount(1, PhoneSignIn::accountsFor(self::NUMBER));
    }

    public function testSigningInWithEmailLinksNothingWithoutAProvenNumber()
    {
        $user = $this->patient('not.linked', ['phone_number' => '0700 111 222']);
        Yii::$app->user->login($user);
        $this->assertNull(User::findOne($user->id)->phone_verified_at);
    }
}
