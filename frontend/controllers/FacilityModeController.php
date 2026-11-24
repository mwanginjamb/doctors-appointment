<?php

namespace frontend\controllers;

use frontend\services\FacilityAdmins;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;

/**
 * A consultant who runs a facility switches between their own practice and managing the facility.
 */
class FacilityModeController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => ['class' => AccessControl::class, 'rules' => [['allow' => true, 'roles' => ['@']]]],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['manage' => ['POST'], 'practice' => ['POST']]],
        ];
    }

    /** Start managing the facility: its board. */
    public function actionManage()
    {
        if (!FacilityAdmins::setManaging(true)) {
            throw new ForbiddenHttpException(Yii::t('app', 'You do not run a facility.'));
        }

        return $this->redirect(['/board/index']);
    }

    /** Back to the consultant's own practice. */
    public function actionPractice()
    {
        FacilityAdmins::setManaging(false);

        return $this->redirect(['/site/index']);
    }
}
