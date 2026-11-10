<?php

namespace frontend\controllers;

use common\models\AccountClosureRequest;
use common\models\Consent;
use frontend\models\Appointments;
use frontend\models\ConsentForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;

/**
 * A patient's consent (F11):
 *  - index: the consent page patients are held on until they agree to the current privacy notice;
 *  - settings: "Privacy & messages", to switch WhatsApp/SMS reminders, see what they agreed to, or
 *    withdraw consent and ask for their account to be closed.
 */
class ConsentController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['reminders' => ['POST'], 'withdraw' => ['POST']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!Consent::isEnabled()) {
            throw new \yii\web\NotFoundHttpException(Yii::t('app', 'Consent recording is switched off.'));
        }
        // Staff have no consent of their own to give here
        if (!Yii::$app->user->identity->isPatientAccount()) {
            throw new ForbiddenHttpException(Yii::t('app', 'This page is for patients.'));
        }
        return true;
    }

    public function actionIndex()
    {
        $userId = Yii::$app->user->id;
        $form = new ConsentForm();
        $latestReminders = Consent::latest($userId, Consent::PURPOSE_REMINDERS);
        if ($latestReminders !== null) {
            $form->reminders = $latestReminders->granted ? '1' : '0';
        }

        if ($form->load($this->request->post()) && $form->validate()) {
            $form->save($userId, Consent::METHOD_ONLINE);
            if ($request = AccountClosureRequest::openFor($userId)) {
                $request->withdraw();
            }
            Yii::$app->session->setFlash('success', Yii::t('app', 'Thank you. Your choices are saved; you can change them any time under Privacy & messages.'));
            return $this->redirect(Yii::$app->user->getReturnUrl(['/site/index']));
        }

        return $this->render('index', [
            'form' => $form,
            'status' => Consent::status($userId),
            'closure' => AccountClosureRequest::openFor($userId),
        ]);
    }

    public function actionSettings()
    {
        $userId = Yii::$app->user->id;

        return $this->render('settings', [
            'remindersOn' => Consent::remindersAllowed($userId),
            'remindersAnswered' => Consent::latest($userId, Consent::PURPOSE_REMINDERS) !== null,
            'history' => Consent::history($userId),
            'closure' => AccountClosureRequest::openFor($userId),
        ]);
    }

    /** Switches WhatsApp/SMS reminders on or off. */
    public function actionReminders()
    {
        $on = (string) $this->request->post('reminders') === '1';
        Consent::recordIfChanged(Yii::$app->user->id, Consent::PURPOSE_REMINDERS, $on, Consent::METHOD_ONLINE);
        Yii::$app->session->setFlash('success', $on
            ? Yii::t('app', 'You will get reminders by WhatsApp/SMS as well as email.')
            : Yii::t('app', 'WhatsApp/SMS reminders are off. You will still get emails.'));

        return $this->redirect(['settings']);
    }

    /**
     * Withdraws consent to data processing: future appointments are cancelled (the consultant is
     * told), messages stop, and admins get a request to close the account.
     */
    public function actionWithdraw()
    {
        $userId = Yii::$app->user->id;
        $reason = trim((string) $this->request->post('reason'));

        Consent::record($userId, Consent::PURPOSE_DATA, false, Consent::METHOD_ONLINE);
        AccountClosureRequest::open($userId, $reason);

        $upcoming = Appointments::find()
            ->where(['patient_id' => $userId])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->andWhere(['>=', 'date', date('Y-m-d')])
            ->all();
        foreach ($upcoming as $appointment) {
            if ($appointment->canBeCancelled()) {
                $appointment->cancel('Cancelled because the patient withdrew consent and asked for their account to be closed.');
            }
        }

        Yii::$app->session->setFlash('success', Yii::t('app', 'Your consent is withdrawn and the clinic has been asked to close your account. Upcoming appointments were cancelled.'));
        return $this->redirect(['index']);
    }
}
