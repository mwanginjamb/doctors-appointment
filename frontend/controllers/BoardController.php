<?php

namespace frontend\controllers;

use frontend\components\AdminAccessControl;
use frontend\models\AppointmentBoardSearch;
use yii\web\Controller;

/**
 * Clinic-wide appointments board: every consultant's appointments, today by default.
 * Admins only for now; front-desk staff get a version limited to their consultants.
 */
class BoardController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => ['class' => AdminAccessControl::class],
        ];
    }

    public function actionIndex()
    {
        $search = new AppointmentBoardSearch();
        $dataProvider = $search->search($this->request->queryParams);

        return $this->render('index', [
            'search' => $search,
            'dataProvider' => $dataProvider,
            'counts' => $search->countsByStatus(),
        ]);
    }
}
