<?php

namespace backend\controllers;

use frontend\models\Consultant;
use frontend\models\ConsultantRating;
use frontend\services\Ratings;
use yii\data\ActiveDataProvider;
use yii\web\Controller;

/**
 * F30: patients' ratings of consultants, for platform admins: each consultant's average and
 * count, and every rating with its patient, visit and comment (for disputes and abuse).
 */
class RatingController extends Controller
{
    public function actionIndex($consultant = null)
    {
        $consultants = Consultant::find()->alias('c')
            ->innerJoin(ConsultantRating::tableName() . ' r', 'r.consultant_id = c.id')
            ->distinct()->orderBy(['c.names' => SORT_ASC])->all();
        $chosen = $consultant ? Consultant::findOne((int) $consultant) : null;
        $query = ConsultantRating::find()->with(['appointment.patient', 'appointment.facility', 'consultant'])->orderBy(['created_at' => SORT_DESC]);
        if ($chosen) {
            $query->andWhere(['consultant_id' => $chosen->id]);
        }

        return $this->render('index', [
            'consultants' => $consultants,
            'chosen' => $chosen,
            'summaries' => array_map(function (Consultant $c) { return ['consultant' => $c, 'summary' => Ratings::summary($c)]; }, $consultants),
            'provider' => new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 30]]),
        ]);
    }
}
