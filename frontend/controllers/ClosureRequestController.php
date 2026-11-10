<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use common\models\AccountClosureRequest;
use common\models\User;
use frontend\components\AdminAccessControl;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Patients who withdrew consent and asked for their account to be closed (F11). Admins deactivate
 * the account and close the request, or close it with a note (for example after talking to the
 * patient). Data export and deletion come with F12.
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
                'actions' => ['close' => ['POST']],
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
}
