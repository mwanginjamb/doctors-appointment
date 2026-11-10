<?php

namespace frontend\controllers;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\AccountForm;
use frontend\models\ChangePasswordForm;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * "My account" for staff (front desk, admins), who have no consultant or patient profile:
 * their details, the consultants a front-desk user books for, and changing their password.
 */
class AccountController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['password' => ['POST']],
            ],
        ];
    }

    public function actionIndex()
    {
        $user = $this->findUser();
        $account = new AccountForm($user);
        if ($account->load($this->request->post()) && $account->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Your details are saved.'));
            return $this->refresh();
        }

        return $this->renderPage($user, $account, new ChangePasswordForm($user));
    }

    public function actionPassword()
    {
        $user = $this->findUser();
        $password = new ChangePasswordForm($user);
        if ($password->load($this->request->post()) && $password->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Your password is changed.'));
            return $this->redirect(['index']);
        }

        return $this->renderPage($user, new AccountForm($user), $password);
    }

    private function renderPage(User $user, AccountForm $account, ChangePasswordForm $password)
    {
        $consultants = $user->isFrontDeskAccount()
            ? Consultant::find()->where(['id' => FrontDeskAssignment::consultantIdsFor($user->id)])->orderBy(['names' => SORT_ASC])->all()
            : [];

        return $this->render('index', [
            'user' => $user,
            'account' => $account,
            'password' => $password,
            'consultants' => $consultants,
        ]);
    }

    /**
     * @return User
     */
    private function findUser()
    {
        return User::findOne(Yii::$app->user->id);
    }
}
