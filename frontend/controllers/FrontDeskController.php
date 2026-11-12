<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Facility;
use frontend\models\FrontDeskAssignment;
use frontend\models\FrontDeskStaffForm;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Front-desk staff: create their accounts, and choose the facility each works at and the consultants
 * they book for there.
 *
 * Consultancy admins manage front desk at every facility. A facility admin manages their own
 * facility's front desk only (permission manageFrontDesk, rule facilityAdmin).
 */
class FrontDeskController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function () {
                        return Yii::$app->user->can(RbacHelper::PERM_MANAGE_FRONT_DESK, ['facilityId' => $this->ownFacilityId()]);
                    },
                ]],
            ],
        ];
    }

    /**
     * A facility admin's facility, the only one they manage; null for consultancy admins (all).
     */
    private function ownFacilityId()
    {
        return FrontDesk::isFacilityAdmin() ? FrontDesk::facilityId() : null;
    }

    public function actionIndex()
    {
        $own = $this->ownFacilityId();
        $staff = User::find()->where(['role' => FrontDesk::ROLE])
            ->andFilterWhere(['facility_id' => $own])
            ->orderBy(['full_name' => SORT_ASC])->all();
        $assigned = [];
        foreach (FrontDeskAssignment::find()->with('consultant')->where(['user_id' => array_column($staff, 'id')])->all() as $row) {
            if ($row->consultant) {
                $assigned[$row->user_id][] = $row->consultant->names;
            }
        }

        $facilities = Facility::find()->select(['name', 'id'])->where(['id' => array_filter(array_column($staff, 'facility_id'))])->indexBy('id')->column();

        return $this->render('index', [
            'staff' => $staff,
            'assigned' => $assigned,
            'facilities' => $facilities,
            'facility' => $own ? Facility::findOne($own) : null,
        ]);
    }

    public function actionCreate()
    {
        $form = new FrontDeskStaffForm(null, ['lockedFacilityId' => $this->ownFacilityId()]);
        if ($form->load($this->request->post()) && $this->checkFacility($form) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Front-desk account created. {name} signs in with {email} and the initial password you set.', [
                'name' => Html::encode($form->full_name),
                'email' => Html::encode($form->email),
            ]));
            $this->flashInvited($form);
            return $this->redirect(['index']);
        }

        return $this->renderForm($form);
    }

    public function actionUpdate($id)
    {
        $user = User::find()->where(['id' => $id, 'role' => FrontDesk::ROLE])
            ->andFilterWhere(['facility_id' => $this->ownFacilityId()])->one();
        if ($user === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Front-desk account not found.'));
        }

        $form = new FrontDeskStaffForm($user, ['lockedFacilityId' => $this->ownFacilityId()]);
        if ($form->load($this->request->post()) && $this->checkFacility($form) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} saved.', ['name' => Html::encode($form->full_name)]));
            $this->flashInvited($form);
            return $this->redirect(['index']);
        }

        return $this->renderForm($form);
    }

    /**
     * The facility chosen is one the user manages (RBAC); the form also fixes a facility admin's.
     */
    private function checkFacility(FrontDeskStaffForm $form)
    {
        $facilityId = $this->ownFacilityId() ?? $form->facility_id;
        if ($facilityId && !Yii::$app->user->can(RbacHelper::PERM_MANAGE_FRONT_DESK, ['facilityId' => (int) $facilityId])) {
            throw new ForbiddenHttpException(Yii::t('app', "You can only manage your own facility's front desk."));
        }

        return true;
    }

    private function flashInvited(FrontDeskStaffForm $form)
    {
        $invited = array_filter(array_map(function ($c) {
            return $c->names ?? null;
        }, $form->getInvited()));
        if ($invited) {
            $facility = Facility::findOne((int) $form->facility_id);
            Yii::$app->session->addFlash('info', Yii::t('app', '{names} {verb} been invited to work at {facility}. Front desk can book for them there once they accept and add their hours.', [
                'names' => Html::encode(implode(', ', $invited)),
                'verb' => count($invited) === 1 ? 'has' : 'have',
                'facility' => Html::encode($facility->name ?? ''),
            ]));
        }
    }

    private function renderForm(FrontDeskStaffForm $form)
    {
        [$consultants, $byFacility, $invited] = FrontDeskStaffForm::consultantOptions();
        $own = $this->ownFacilityId();

        return $this->render('form', [
            'form' => $form,
            'consultants' => $consultants,
            'byFacility' => $byFacility,
            'invited' => $invited,
            'facilities' => Facility::find()->select(['name', 'id'])->andFilterWhere(['id' => $own])->orderBy(['name' => SORT_ASC])->indexBy('id')->column(),
            'locked' => $own !== null,
        ]);
    }
}
