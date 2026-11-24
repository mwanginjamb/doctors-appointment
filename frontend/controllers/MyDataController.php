<?php

namespace frontend\controllers;

use common\models\AccountClosureRequest;
use frontend\services\PatientData;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;

/**
 * F12: "My data", for patients:
 *  - index: download the data, or ask for it to be deleted;
 *  - view: everything held about them, as a page to read, print or save as PDF;
 *  - download: the same as a JSON file;
 *  - delete: asks the clinic to delete it (admins do it from Closure Requests).
 *
 * Open to patients held on the consent page too (ConsentGate), and when consent recording is off.
 */
class MyDataController extends Controller
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
                'actions' => ['delete' => ['POST']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!Yii::$app->user->identity->isPatientAccount()) {
            throw new ForbiddenHttpException(Yii::t('app', 'This page is for patients.'));
        }

        return true;
    }

    public function actionIndex()
    {
        $user = Yii::$app->user->identity;

        return $this->render('index', [
            'request' => AccountClosureRequest::openFor($user->id),
            'problems' => PatientData::deletionProblems($user),
        ]);
    }

    public function actionView()
    {
        $user = Yii::$app->user->identity;
        PatientData::logExport($user, 'Viewed by the patient');

        return $this->render('view', ['data' => PatientData::export($user)]);
    }

    public function actionDownload()
    {
        $user = Yii::$app->user->identity;
        PatientData::logExport($user, 'Downloaded by the patient (JSON)');

        return Yii::$app->response->sendContentAsFile(
            json_encode(PatientData::export($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'my-data-' . date('Y-m-d') . '.json',
            ['mimeType' => 'application/json']
        );
    }

    /** Asks the clinic to delete the patient's data. */
    public function actionDelete()
    {
        if ((string) $this->request->post('confirm') !== '1') {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Tick the box to confirm you want your data deleted.'));
            return $this->redirect(['index']);
        }
        PatientData::requestDeletion(Yii::$app->user->identity, trim((string) $this->request->post('reason')));
        Yii::$app->session->setFlash('success', Yii::t('app', 'Your request is sent. Upcoming appointments were cancelled, and the clinic will delete your data and close your account.'));

        return $this->redirect(['index']);
    }
}
