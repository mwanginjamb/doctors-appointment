<?php

namespace frontend\controllers;

use common\models\User;
use frontend\components\AdminAccessControl;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\NewConsultantForm;
use Yii;
use yii\helpers\Html;
use yii\web\Controller;

/**
 * Admin list of every consultant, with links to edit their profile, hours and time off, open their
 * calendar, and deactivate or reactivate their account. Admins can also add a doctor here.
 */
class ManageConsultantsController extends Controller
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
        $consultants = Consultant::find()->with(['user', 'workingHours'])->orderBy(['names' => SORT_ASC])->all();

        // Upcoming active appointments per consultant user id
        $upcoming = Appointments::find()
            ->select(['n' => 'COUNT(*)', 'consultant_id'])
            ->where(['>=', 'date', date('Y-m-d')])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->groupBy('consultant_id')
            ->orderBy(null)
            ->indexBy('consultant_id')
            ->column();

        return $this->render('index', ['consultants' => $consultants, 'upcoming' => $upcoming]);
    }

    public function actionCreate()
    {
        $form = new NewConsultantForm();
        if ($form->load($this->request->post()) && $form->save()) {
            // The next page uses the main layout, whose alerts do not escape: escape the names here
            Yii::$app->session->setFlash('success', Html::encode($form->full_name) . ' added with the default week (Mon–Sat 08:00–13:00, 14:00–17:00). Complete their profile now; they sign in with ' . Html::encode($form->email) . '.');
            return $this->redirect(['consultant/update', 'id' => $form->consultant->id]);
        }

        return $this->render('create', ['form' => $form]);
    }
}
