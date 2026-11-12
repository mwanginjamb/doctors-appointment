<?php

namespace frontend\controllers;

use common\models\User;
use frontend\components\AdminAccessControl;
use frontend\models\Facility;
use frontend\models\FacilityAdminForm;
use Yii;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Consultancy admins create facility admin accounts: each runs one facility (its board, front desk,
 * closed days, contact details and audit log).
 */
class FacilityAdminController extends Controller
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
        $admins = User::find()->where(['role' => User::ACCOUNT_FACILITY_ADMIN])->orderBy(['full_name' => SORT_ASC])->all();
        $facilities = Facility::find()->select(['name', 'id'])->where(['id' => array_filter(array_column($admins, 'facility_id'))])->indexBy('id')->column();

        return $this->render('index', ['admins' => $admins, 'facilities' => $facilities]);
    }

    public function actionCreate()
    {
        $form = new FacilityAdminForm();
        if ($form->load($this->request->post()) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Facility admin created. {name} signs in with {email} and the initial password you set.', [
                'name' => Html::encode($form->full_name),
                'email' => Html::encode($form->email),
            ]));
            return $this->redirect(['index']);
        }

        return $this->renderForm($form);
    }

    public function actionUpdate($id)
    {
        $user = User::findOne(['id' => $id, 'role' => User::ACCOUNT_FACILITY_ADMIN]);
        if ($user === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Facility admin not found.'));
        }

        $form = new FacilityAdminForm($user);
        if ($form->load($this->request->post()) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} saved.', ['name' => Html::encode($form->full_name)]));
            return $this->redirect(['index']);
        }

        return $this->renderForm($form);
    }

    private function renderForm(FacilityAdminForm $form)
    {
        return $this->render('form', [
            'form' => $form,
            'facilities' => Facility::find()->select(['name', 'id'])->orderBy(['name' => SORT_ASC])->indexBy('id')->column(),
        ]);
    }
}
