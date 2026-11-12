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
            // Front desk and facility admins get the headline numbers here; consultancy admins on the dashboard
            'stats' => FrontDesk::isFrontDesk() || FrontDesk::isFacilityAdmin() ? new ClinicStats($scope) : null,
            'facility' => $scope['facilityId'] ? Facility::findOne($scope['facilityId']) : null,
            'facilityAdmin' => FrontDesk::isFacilityAdmin(),
            // Facility admins open these consultants' calendars and sessions
            'here' => FrontDesk::isFacilityAdmin() && $scope['facilityId']
                ? Consultant::find()->innerJoin('{{%consultant_facility}} cf', 'cf.consultant_id = consultant.id')
                    ->where(['cf.facility_id' => $scope['facilityId']])->orderBy(['names' => SORT_ASC])->all()
                : [],
        ] + $this->frontDeskConsultants($scope['facilityId']));
    }

    /**
     * Front desk books from the calendars of their consultants who work at their facility; the
     * others are invited and not bookable yet.
     *
     * @return array ['bookable' => Consultant[], 'waiting' => Consultant[]]
     */
    private function frontDeskConsultants($facilityId)
    {
        if (!FrontDesk::isFrontDesk()) {
            return ['bookable' => [], 'waiting' => []];
        }
        $bookable = [];
        $waiting = [];
        $consultants = Consultant::find()->where(['user_id' => FrontDesk::consultantUserIds()])->with('engagements')->orderBy(['names' => SORT_ASC])->all();
        foreach ($consultants as $consultant) {
            if ($facilityId === null || $consultant->getEngagement($facilityId) !== null) {
                $bookable[] = $consultant;
            } else {
                $waiting[] = $consultant;
            }
        }

        return ['bookable' => $bookable, 'waiting' => $waiting];
    }
}
