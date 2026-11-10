<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use frontend\components\AdminAccessControl;
use frontend\models\Appointments;
use frontend\models\PublicHoliday;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Admin list of public holidays. The clinic is closed on these dates for every consultant.
 */
class HolidayController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AdminAccessControl::class,
                'permission' => RbacHelper::PERM_MANAGE_REFERENCE_DATA,
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['delete' => ['POST']],
            ],
        ];
    }

    /**
     * Holidays from the start of last year onwards, soonest first.
     */
    public function actionIndex()
    {
        $dataProvider = new ActiveDataProvider([
            'query' => PublicHoliday::find()->where(['>=', 'date', (date('Y') - 1) . '-01-01']),
            'sort' => ['defaultOrder' => ['date' => SORT_ASC], 'attributes' => ['date', 'name']],
            'pagination' => ['pageSize' => 50],
        ]);

        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionCreate()
    {
        $model = new PublicHoliday();
        if ($model->load($this->request->post()) && $model->save()) {
            $this->flashSaved($model);
            return $this->redirect(['index']);
        }

        return $this->render('create', ['model' => $model]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        if ($model->load($this->request->post()) && $model->save()) {
            $this->flashSaved($model);
            return $this->redirect(['index']);
        }

        return $this->render('update', ['model' => $model]);
    }

    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $model->delete();
        Yii::$app->session->setFlash('success', Yii::t('app', '{name} removed; {date} can be booked again.', [
            'name' => $model->name,
            'date' => Yii::$app->formatter->asDate(strtotime($model->date), 'php:j M Y'),
        ]));

        return $this->redirect(['index']);
    }

    /**
     * Confirms the save and warns about appointments already booked that day. They are kept;
     * the consultants concerned see the holiday on their calendars and can cancel or move them.
     */
    private function flashSaved(PublicHoliday $model)
    {
        $session = Yii::$app->session;
        $session->setFlash('success', Yii::t('app', '{name} saved. Nobody can be booked on {date}.', [
            'name' => $model->name,
            'date' => Yii::$app->formatter->asDate(strtotime($model->date), 'php:l j M Y'),
        ]));

        $booked = Appointments::find()
            ->where(['date' => $model->date])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->count();
        if ($booked > 0) {
            $session->addFlash('warning', Yii::t('app', '{n} appointment(s) are already booked on that date. They are kept; their consultants need to cancel or move them.', ['n' => $booked]));
        }
    }

    protected function findModel($id)
    {
        if (($model = PublicHoliday::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
