<?php

namespace frontend\controllers;

use common\models\User;
use frontend\components\AdminAccessControl;
use frontend\components\FrontDesk;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use frontend\models\FrontDeskStaffForm;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Admin page for front-desk staff: create their accounts and choose which consultants each books for.
 */
class FrontDeskController extends Controller
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
        $staff = User::find()->where(['role' => FrontDesk::ROLE])->orderBy(['full_name' => SORT_ASC])->all();
        $assigned = [];
        foreach (FrontDeskAssignment::find()->with('consultant')->where(['user_id' => array_column($staff, 'id')])->all() as $row) {
            if ($row->consultant) {
                $assigned[$row->user_id][] = $row->consultant->names;
            }
        }

        return $this->render('index', ['staff' => $staff, 'assigned' => $assigned]);
    }

    public function actionCreate()
    {
        $form = new FrontDeskStaffForm();
        if ($form->load($this->request->post()) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Front-desk account created. {name} signs in with {email} and the initial password you set.', [
                'name' => $form->full_name,
                'email' => $form->email,
            ]));
            return $this->redirect(['index']);
        }

        return $this->render('form', ['form' => $form, 'consultants' => $this->consultantOptions()]);
    }

    public function actionUpdate($id)
    {
        $user = User::findOne(['id' => $id, 'role' => FrontDesk::ROLE]);
        if ($user === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Front-desk account not found.'));
        }

        $form = new FrontDeskStaffForm($user);
        if ($form->load($this->request->post()) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} saved.', ['name' => $form->full_name]));
            return $this->redirect(['index']);
        }

        return $this->render('form', ['form' => $form, 'consultants' => $this->consultantOptions()]);
    }

    /**
     * @return array consultant.id => "Name (practice)"
     */
    private function consultantOptions()
    {
        $options = [];
        foreach (Consultant::find()->orderBy(['names' => SORT_ASC])->all() as $consultant) {
            $options[$consultant->id] = $consultant->names . ($consultant->practice_name ? ' (' . $consultant->practice_name . ')' : '');
        }

        return $options;
    }
}
