<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use common\models\AccountClosureRequest;
use common\models\User;
use frontend\services\PatientData;
use frontend\components\AdminAccessControl;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Patients who withdrew consent and asked for their account to be closed (F11). Admins deactivate
 * the account and close the request, or close it with a note (for example after talking to the
 * patient). F12: they also see or download the patient's data and delete it (anonymise).
 */
class ClosureRequestController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AdminAccessControl::class,
                'permission' => RbacHelper::PERM_MANAGE_USERS,
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['close' => ['POST'], 'delete-data' => ['POST']],
            ],
        ];
    }

    public function actionIndex($status = AccountClosureRequest::STATUS_OPEN)
    {
        $query = AccountClosureRequest::find()->with(['user', 'handler'])->orderBy(['created_at' => SORT_DESC]);
        if ($status !== '') {
            $query->andWhere(['status' => $status]);
        }

        return $this->render('index', [
            'dataProvider' => new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 30], 'sort' => false]),
            'status' => $status,
        ]);
    }

    /**
     * Closes the request; with deactivate=1 the account is deactivated as well.
     */
    public function actionClose($id)
    {
        $request = AccountClosureRequest::findOne(['id' => $id, 'status' => AccountClosureRequest::STATUS_OPEN]);
        if ($request === null) {
            throw new NotFoundHttpException('No open request with that number.');
        }
        $deactivate = (string) $this->request->post('deactivate') === '1';
        $note = (string) $this->request->post('note');

        if ($deactivate && $request->user && (int) $request->user->status !== User::STATUS_DELETED) {
            $request->user->status = User::STATUS_DELETED;
            $request->user->save(false);
            $note = trim('Account deactivated. ' . $note);
        }
        $request->close($note);

        $name = $request->user ? ($request->user->full_name ?: $request->user->username) : 'The patient';
        Yii::$app->session->setFlash('success', $deactivate
            ? "$name's account is deactivated and the request is closed."
            : 'The request is closed.');

        return $this->redirect(['index']);
    }

    /** F12: the patient's data, to read or print; format=json downloads it. */
    public function actionData($id, $format = 'html')
    {
        $request = $this->findRequest($id);
        if ($request->user === null) {
            throw new NotFoundHttpException('The account no longer exists.');
        }
        $data = PatientData::export($request->user);
        if ($format === 'json') {
            PatientData::logExport($request->user, "Downloaded by an admin (JSON), closure request #{$request->id}");
            return Yii::$app->response->sendContentAsFile(
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'patient-' . (int) $request->user_id . '-data-' . date('Y-m-d') . '.json',
                ['mimeType' => 'application/json']
            );
        }
        PatientData::logExport($request->user, "Viewed by an admin, closure request #{$request->id}");

        return $this->render('data', ['request' => $request, 'data' => $data]);
    }

    /**
     * F12: deletes the patient's data (anonymises the account) and closes the request. Refused
     * while the clinic still owes the patient money.
     */
    public function actionDeleteData($id)
    {
        $request = $this->findRequest($id);
        if ($request->status !== AccountClosureRequest::STATUS_OPEN || $request->user === null) {
            throw new NotFoundHttpException('No open request with that number.');
        }
        if ($problems = PatientData::deletionProblems($request->user)) {
            Yii::$app->session->setFlash('error', 'Not deleted: ' . implode(' ', $problems));
            return $this->redirect(['index']);
        }
        $name = $request->user->full_name ?: $request->user->username;
        PatientData::anonymise($request->user, $request->delete_data ? "at the patient's request" : 'by an admin on a closure request');
        $note = trim((string) $this->request->post('note'));
        if ($note !== '') {
            $request->refresh();
            $request->updateAttributes(['handled_note' => mb_substr($request->handled_note . ' ' . $note, 0, 500)]);
        }
        Yii::$app->session->setFlash('success', "$name's data is deleted, the account is closed and so is the request.");

        return $this->redirect(['index']);
    }

    private function findRequest($id)
    {
        $request = AccountClosureRequest::findOne((int) $id);
        if ($request === null) {
            throw new NotFoundHttpException('No request with that number.');
        }

        return $request;
    }
}
