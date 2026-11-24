<?php

namespace backend\controllers;

use common\models\User;
use frontend\models\FacilityAdminClaim;
use frontend\services\FacilityAdmins;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Consultants who run a facility, for platform admins: claims waiting for an answer (approve after
 * checking with the facility, or decline with a reason), who runs what now (revoke), and recent
 * answers. Facility admin accounts (not consultants) are managed on the main site.
 */
class FacilityAdminController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['approve' => ['POST'], 'decline' => ['POST'], 'revoke' => ['POST']],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index', [
            'pending' => FacilityAdminClaim::find()->with(['user', 'facility', 'facilityRequest'])
                ->where(['status' => FacilityAdminClaim::STATUS_PENDING])->orderBy(['created_at' => SORT_ASC])->all(),
            'admins' => FacilityAdmins::consultantAdmins(),
            'recent' => FacilityAdminClaim::find()->with(['user', 'facility', 'facilityRequest', 'decider'])
                ->where(['not', ['status' => FacilityAdminClaim::STATUS_PENDING]])
                ->orderBy(['decided_at' => SORT_DESC, 'id' => SORT_DESC])->limit(30)->all(),
        ]);
    }

    public function actionApprove($id)
    {
        $claim = $this->findClaim($id);
        if (!$claim->facility_id) {
            Yii::$app->session->setFlash('error', 'This claim waits for its facility to be listed; it is granted when the facility request is approved.');
            return $this->redirect(['index']);
        }
        $result = FacilityAdmins::approve($claim);
        Yii::$app->session->setFlash($result === true ? 'success' : 'error', $result === true
            ? ($claim->user->full_name ?: $claim->user->username) . ' now runs ' . $claim->facilityName() . '.'
            : $result);

        return $this->redirect(['index']);
    }

    public function actionDecline($id)
    {
        $claim = $this->findClaim($id);
        $result = FacilityAdmins::decline($claim, $this->request->post('reason'));
        Yii::$app->session->setFlash($result === true ? 'success' : 'error', $result === true ? 'Declined; the consultant is told.' : $result);

        return $this->redirect(['index']);
    }

    public function actionRevoke($id)
    {
        $user = User::findOne((int) $id);
        if ($user === null) {
            throw new NotFoundHttpException('No such account.');
        }
        $result = FacilityAdmins::revoke($user, $this->request->post('reason'));
        Yii::$app->session->setFlash($result === true ? 'success' : 'error', $result === true
            ? ($user->full_name ?: $user->username) . ' no longer runs the facility; they stay a consultant.'
            : $result);

        return $this->redirect(['index']);
    }

    private function findClaim($id)
    {
        $claim = FacilityAdminClaim::findOne((int) $id);
        if ($claim === null) {
            throw new NotFoundHttpException('No such claim.');
        }

        return $claim;
    }
}
