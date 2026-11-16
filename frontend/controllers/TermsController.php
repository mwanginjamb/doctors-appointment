<?php

namespace frontend\controllers;

use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use frontend\services\Pilot;
use frontend\services\StandardTerms;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * A facility's standard terms after its pilot: what they are, where the facility stands, and the
 * buttons for the two steps. Read by its consultants, front desk and facility admin, and platform
 * admins; confirmed by its front desk or facility admin (or a platform admin for it), then
 * approved by a platform admin.
 */
class TermsController extends Controller
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
                'actions' => ['confirm' => ['POST'], 'approve' => ['POST']],
            ],
        ];
    }

    public function actionView($facility)
    {
        $model = $this->findFacility($facility);
        $identity = Yii::$app->user->identity;

        return $this->render('view', [
            'facility' => $model,
            'agreement' => FacilityAgreement::currentFor($model->id),
            'status' => Pilot::termsStatus($model->id),
            'start' => Pilot::standardTermsStart($model->id),
            'graceEnds' => Pilot::graceEnds($model->id),
            'canConfirm' => Pilot::canConfirm($identity, $model->id),
            'canApprove' => Pilot::canApprove($identity),
        ]);
    }

    public function actionConfirm($facility)
    {
        $model = $this->findFacility($facility);
        if (!Pilot::canConfirm(Yii::$app->user->identity, $model->id)) {
            Yii::$app->session->setFlash('error', Yii::t('app', "Only the facility's front desk or facility admin can confirm its terms."));
        } elseif (StandardTerms::confirm($model->id, Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Thank you. Standard terms are confirmed for {facility}; the platform will now approve them.', ['facility' => $model->name]));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'There is no agreement to confirm, or it is already confirmed.'));
        }

        return $this->redirect(['view', 'facility' => $model->id]);
    }

    public function actionApprove($facility)
    {
        $model = $this->findFacility($facility);
        if (!Pilot::canApprove(Yii::$app->user->identity)) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Only a platform admin can approve standard terms.'));
        } elseif (StandardTerms::approve($model->id, Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Standard terms are approved for {facility}. The facility has been told.', ['facility' => $model->name]));
        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'The facility has not confirmed the terms yet, or they are already approved.'));
        }

        return $this->redirect(['view', 'facility' => $model->id]);
    }

    /** The facility, if the user may see its terms; a 404 otherwise, so others learn nothing. */
    private function findFacility($id)
    {
        $model = Facility::findOne((int) $id);
        if ($model === null || !Pilot::canView(Yii::$app->user->identity, $model->id)) {
            throw new NotFoundHttpException(Yii::t('app', 'The page does not exist.'));
        }

        return $model;
    }
}
