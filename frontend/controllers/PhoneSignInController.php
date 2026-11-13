<?php

namespace frontend\controllers;

use common\components\PhoneMessenger;
use common\helpers\Phone;
use common\models\LoginCode;
use frontend\services\PhoneSignIn;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Patients sign in with their phone number and a code (see PhoneSignIn):
 * index (the number) → code (the code; send again, or by SMS) → choose (which account on the
 * number, or a new one).
 */
class PhoneSignInController extends Controller
{
    public $layout = 'guest';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['?']]],
                'denyCallback' => function () {
                    return $this->goHome();
                },
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['resend' => ['post'], 'sign-in' => ['post'], 'create' => ['post']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!PhoneSignIn::isAvailable()) {
            Yii::$app->session->setFlash('error', 'Signing in with your phone is not available right now. Please sign in with your email or Google.');
            $this->redirect(['site/login']);
            return false;
        }

        return true;
    }

    /**
     * Step 1: the phone number.
     */
    public function actionIndex()
    {
        $phone = '';
        $error = null;
        if (Yii::$app->request->isPost) {
            $phone = trim((string) Yii::$app->request->post('phone'));
            $result = PhoneSignIn::requestCode($phone, null, Yii::$app->request->userIP);
            if ($result['status'] === 'sent') {
                return $this->redirect(['code']);
            }
            $error = self::requestError($result['status']);
        }

        return $this->render('index', ['phone' => $phone, 'error' => $error]);
    }

    /**
     * Step 2: the code.
     */
    public function actionCode()
    {
        $pending = PhoneSignIn::pending();
        if ($pending === null) {
            return $this->redirect(['index']);
        }
        $error = null;
        if (Yii::$app->request->isPost) {
            $result = PhoneSignIn::verifyCode($pending['phone'], Yii::$app->request->post('code'));
            if ($result === 'ok') {
                Yii::$app->session->set('phoneSignIn.remember', (bool) Yii::$app->request->post('remember'));
                return $this->redirect(['choose']);
            }
            $error = $result === 'wrong'
                ? 'That code is not right. Check the message and try again.'
                : 'That code no longer works. Send a new one.';
        }
        $params = LoginCode::params();

        return $this->render('code', [
            'pending' => $pending,
            'error' => $error,
            'resendIn' => max(0, (int) $pending['sentAt'] + $params['resendAfterSeconds'] - time()),
            'channels' => Yii::$app->get('phoneMessenger')->channels(),
        ]);
    }

    /**
     * Sends the code again, by the channel asked for (WhatsApp or SMS).
     */
    public function actionResend()
    {
        $pending = PhoneSignIn::pending();
        if ($pending === null) {
            return $this->redirect(['index']);
        }
        if ((int) $pending['sentAt'] + LoginCode::params()['resendAfterSeconds'] > time()) {
            Yii::$app->session->setFlash('warning', 'Please wait a minute before asking for another code.');
            return $this->redirect(['code']);
        }
        $channel = Yii::$app->request->post('channel');
        $channel = in_array($channel, [PhoneMessenger::CHANNEL_WHATSAPP, PhoneMessenger::CHANNEL_SMS], true) ? $channel : null;
        $result = PhoneSignIn::requestCode($pending['phone'], $channel, Yii::$app->request->userIP);
        if ($result['status'] === 'sent') {
            Yii::$app->session->setFlash('success', 'A new code is on its way by ' . self::channelName($result['channel']) . '.');
        } else {
            Yii::$app->session->setFlash('error', self::requestError($result['status']));
        }

        return $this->redirect(['code']);
    }

    /**
     * Step 3: which account on the number, or a new one.
     */
    public function actionChoose()
    {
        $phone = PhoneSignIn::provenPhone();
        if ($phone === null) {
            return $this->redirect(['index']);
        }

        return $this->render('choose', [
            'phone' => $phone,
            'accounts' => PhoneSignIn::accountsFor($phone),
            'unlinked' => PhoneSignIn::unlinkedCount($phone),
        ]);
    }

    public function actionSignIn()
    {
        $phone = PhoneSignIn::provenPhone();
        if ($phone === null) {
            return $this->redirect(['index']);
        }
        $remember = (bool) Yii::$app->session->get('phoneSignIn.remember');
        if (!PhoneSignIn::signIn($phone, Yii::$app->request->post('account'), $remember)) {
            Yii::$app->session->setFlash('error', 'Choose one of the accounts listed.');
            return $this->redirect(['choose']);
        }
        Yii::$app->session->remove('phoneSignIn.remember');

        return $this->goBack();
    }

    public function actionCreate()
    {
        $phone = PhoneSignIn::provenPhone();
        if ($phone === null) {
            return $this->redirect(['index']);
        }
        $name = trim((string) Yii::$app->request->post('full_name'));
        if (mb_strlen($name) < 2) {
            Yii::$app->session->setFlash('error', 'Enter your full name to create your account.');
            return $this->redirect(['choose']);
        }
        $remember = (bool) Yii::$app->session->get('phoneSignIn.remember');
        if (PhoneSignIn::createAccount($phone, $name, $remember) === null) {
            Yii::$app->session->setFlash('error', 'We could not create your account. Please try again shortly.');
            return $this->redirect(['choose']);
        }
        Yii::$app->session->remove('phoneSignIn.remember');
        Yii::$app->session->setFlash('success', 'Welcome! Your account is ready.');

        return $this->goHome();
    }

    public static function channelName($channel)
    {
        return $channel === PhoneMessenger::CHANNEL_SMS ? 'SMS' : 'WhatsApp';
    }

    private static function requestError($status)
    {
        switch ($status) {
            case 'invalid':
                return 'Enter a phone number such as 0712 345 678 or +254 712 345 678.';
            case 'limited':
                return 'Too many codes were asked for. Please wait 15 minutes and try again, or sign in with your email.';
            case 'unavailable':
                return 'Signing in with your phone is not available right now. Please sign in with your email or Google.';
        }

        return 'We could not send a code just now. Please try again in a few minutes, or sign in with your email.';
    }
}
