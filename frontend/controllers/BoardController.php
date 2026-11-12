<?php

namespace frontend\controllers;

use frontend\components\FrontDesk;
use frontend\models\AppointmentBoardSearch;
use frontend\models\ClinicStats;
use frontend\models\Consultant;
use frontend\models\Facility;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Appointments board: admins see every consultant's appointments; front desk sees only the
 * consultants assigned to them, and books for them from their calendars.
 */
class BoardController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => function () {
                            return FrontDesk::isStaff();
                        },
                    ],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        // Front desk sees their consultants at their facility only (F8 phase 2)
        $scope = ['consultantUserIds' => FrontDesk::consultantUserIds(), 'facilityId' => FrontDesk::facilityId()];
        $search = new AppointmentBoardSearch($scope);
        $dataProvider = $search->search($this->request->queryParams);

        return $this->render('index', [
            'search' => $search,
            'dataProvider' => $dataProvider,
            'counts' => $search->countsByStatus(),
            'needsOutcome' => $search->needsOutcome(),
            // Front desk gets the headline numbers for their consultants here; admins on the dashboard
            'stats' => FrontDesk::isFrontDesk() ? new ClinicStats($scope) : null,
            'facility' => $scope['facilityId'] ? Facility::findOne($scope['facilityId']) : null,
            // Front desk books from these consultants' calendars
            'bookable' => FrontDesk::isFrontDesk()
                ? Consultant::find()->where(['user_id' => FrontDesk::consultantUserIds()])->orderBy(['names' => SORT_ASC])->all()
                : [],
        ]);
    }
}
