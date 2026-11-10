<?php

namespace frontend\tests\unit;

use common\models\Consent;
use frontend\components\ConsentGate;
use frontend\models\RegisterPatientForm;
use frontend\models\SignupForm;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\base\ActionEvent;
use yii\base\InlineAction;
use yii\web\Controller;

/**
 * F11 switched off with CONSENT_ENABLED=false: nobody is asked or held, answers already given are
 * kept and an explicit "no" to WhatsApp/SMS still counts.
 */
class ConsentSwitchTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var \common\models\User a patient with no consent recorded */
    private $legacy;

    private $wasEnabled;

    protected function _before()
    {
        $this->createParticipants();
        $this->legacy = $this->createUser('legacy.patient', 'consultant');
        $this->legacy->updateAttributes(['role' => 'client']);
        $this->wasEnabled = Yii::$app->params['consent.enabled'] ?? true;
    }

    protected function _after()
    {
        Yii::$app->params['consent.enabled'] = $this->wasEnabled;
        Yii::$app->user->setIdentity(null);
    }

    private function switchOff()
    {
        Yii::$app->params['consent.enabled'] = false;
    }

    /** Runs the consent gate for a page a patient opens; true when they get through. */
    private function gateLetsThrough($user)
    {
        Yii::$app->user->setIdentity($user);
        Yii::$app->request->setUrl('/appointments/index'); // as for a page request (the gate keeps it to return to)
        $controller = new Controller('appointments', Yii::$app);
        $event = new ActionEvent(new InlineAction('index', $controller, 'actionIndex'));
        (new ConsentGate())->check($event);
        return $event->isValid;
    }

    public function testTheGateHoldsPatientsOnlyWhileOn()
    {
        $this->assertTrue(Consent::isEnabled());
        $this->assertFalse($this->gateLetsThrough($this->legacy), 'held while on');

        $this->switchOff();
        $this->assertTrue($this->gateLetsThrough($this->legacy), 'let through while off');
    }

    public function testWalkInsRegisterWithoutConsentWhileOff()
    {
        $this->switchOff();
        Yii::$app->user->setIdentity($this->consultantUser);

        $form = new RegisterPatientForm(['full_name' => 'Walk In', 'phone' => '0799000555', 'confirm_new' => true]);
        $patient = $form->register();

        $this->assertNotNull($patient, json_encode($form->errors));
        $this->assertSame('none', Consent::status($patient->id), 'nothing recorded while off');
    }

    public function testSignUpDoesNotAskWhileOff()
    {
        $form = new SignupForm(['role' => 'client']);
        $this->assertTrue($form->asksConsent());
        $form->validate(['consent_data', 'consent_reminders']);
        $this->assertTrue($form->hasErrors('consent_data'));

        $this->switchOff();
        $form = new SignupForm(['role' => 'client']);
        $this->assertFalse($form->asksConsent());
        $form->validate(['consent_data', 'consent_reminders']);
        $this->assertFalse($form->hasErrors('consent_data'));
        $this->assertFalse($form->hasErrors('consent_reminders'));
    }

    public function testAnExplicitNoIsStillRespectedWhileOff()
    {
        Consent::record($this->client->id, Consent::PURPOSE_REMINDERS, false, Consent::METHOD_ONLINE);
        $this->switchOff();

        $this->assertFalse(Consent::remindersAllowed($this->client->id), 'their "no" stands');
        $this->assertTrue(Consent::remindersAllowed($this->legacy->id), 'others get them as before F11');
        $this->assertSame('given', Consent::status($this->client->id), 'earlier answers are kept');
    }
}
