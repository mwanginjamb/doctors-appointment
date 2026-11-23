<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\models\ConsultantRating;
use frontend\services\Ratings;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * F30: a consultant's own ratings (My ratings): the average, how many of each star, and each
 * rating with its comment and date, without the patient's name.
 */
class RatingsController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function () { return Yii::$app->user->identity->isConsultantAccount(); },
                ]],
            ],
        ];
    }

    public function actionIndex()
    {
        $consultant = Consultant::findOne(['user_id' => Yii::$app->user->id]);
        $provider = new ActiveDataProvider([
            'query' => ConsultantRating::find()->with('appointment.facility')
                ->where(['consultant_id' => $consultant->id ?? 0, 'withdrawn' => 0])->orderBy(['created_at' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('index', [
            'consultant' => $consultant,
            'summary' => $consultant ? Ratings::summary($consultant) : null,
            'provider' => $provider,
        ]);
    }
}
