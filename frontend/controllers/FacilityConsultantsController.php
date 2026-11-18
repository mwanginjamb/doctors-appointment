<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use frontend\components\FrontDesk;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FacilityInvitation;
use frontend\models\NewConsultantForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F28: the consultants at a facility. A facility admin invites an existing consultant to their
 * facility (FacilityInvitation: the consultant accepts by adding it on Availability, with their
 * hours, length and fee there), or adds a new consultant account that starts there with the
 * default week.
 *
 * Permission manageFacilityConsultants: consultancy admins for any facility (?facility=<id>), a
 * facility admin for their own through the facilityAdmin rule.
 */
class FacilityConsultantsController extends Controller
{
    public $layout = 'admin';

    /** @var Facility|null */
    private $facility;

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => function () {
                        $id = $this->facilityId();
                        return $id !== null && Yii::$app->user->can(RbacHelper::PERM_MANAGE_FACILITY_CONSULTANTS, ['facilityId' => $id]);
                    },
                ]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['invite' => ['POST']],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->facility = Facility::findOne($this->facilityId());
        if ($this->facility === null) {
            throw new NotFoundHttpException(Yii::t('app', 'Facility not found.'));
        }

        return true;
    }

    /** A facility admin's own facility; for others, the one named in the request. */
    private function facilityId()
    {
        if (FrontDesk::isFacilityAdmin()) {
            return FrontDesk::facilityId() ?: null;
        }
        $id = Yii::$app->request->get('facility');

        return is_numeric($id) ? (int) $id : null;
    }

    /** Route params that keep the facility for consultancy admins. */
    private function keep(array $route)
    {
        return FrontDesk::isFacilityAdmin() ? $route : $route + ['facility' => $this->facility->id];
    }

    public function actionIndex()
    {
        $facilityId = (int) $this->facility->id;
        $here = Consultant::find()->with('user')
            ->innerJoin(ConsultantFacility::tableName() . ' cf', 'cf.consultant_id = consultant.id')
            ->where(['cf.facility_id' => $facilityId])
            ->orderBy(['consultant.names' => SORT_ASC])->all();
        $invitations = FacilityInvitation::find()->with('consultant')
            ->where(['facility_id' => $facilityId, 'status' => FacilityInvitation::STATUS_PENDING])
            ->orderBy(['created_at' => SORT_ASC])->all();

        // Who can be invited: everyone else not already invited
        $exclude = array_merge(array_column($here, 'id'), array_map('intval', array_column($invitations, 'consultant_id')));
        $others = Consultant::find()->select(['names', 'id'])
            ->andFilterWhere(['not in', 'id', $exclude ?: null])
            ->andWhere(['not', ['names' => null]])
            ->orderBy(['names' => SORT_ASC])->indexBy('id')->column();

        return $this->render('index', [
            'facility' => $this->facility,
            'here' => $here,
            'invitations' => $invitations,
            'others' => $others,
            'keep' => FrontDesk::isFacilityAdmin() ? [] : ['facility' => $this->facility->id],
        ]);
    }

    /** Invites a consultant who works elsewhere; they are emailed and WhatsApped. */
    public function actionInvite()
    {
        $consultant = Consultant::findOne((int) $this->request->post('consultant_id'));
        if ($consultant === null) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Choose a consultant to invite.'));
        } elseif (FacilityInvitation::invite($this->facility->id, $consultant->id)) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} is invited to work at {facility}. They accept by adding it on their Availability page, with their hours there.', [
                'name' => Html::encode($consultant->names),
                'facility' => Html::encode($this->facility->name),
            ]));
        } else {
            Yii::$app->session->setFlash('info', Yii::t('app', '{name} already works here or is already invited.', ['name' => Html::encode($consultant->names)]));
        }

        return $this->redirect($this->keep(['index']));
    }

    /** Adds a new consultant account that starts at this facility. */
    public function actionCreate()
    {
        $form = new NewConsultantForm(['facilityId' => $this->facility->id]);
        if ($form->load($this->request->post()) && $form->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', '{name} added at {facility} with the default week (Mon–Sat 08:00–13:00, 14:00–17:00). They sign in with {email}, complete their profile and set their hours, session length and fee there on Availability.', [
                'name' => Html::encode($form->full_name),
                'facility' => Html::encode($this->facility->name),
                'email' => Html::encode($form->email),
            ]));
            return $this->redirect($this->keep(['index']));
        }

        return $this->render('create', ['form' => $form, 'facility' => $this->facility, 'keep' => FrontDesk::isFacilityAdmin() ? [] : ['facility' => $this->facility->id]]);
    }
}
