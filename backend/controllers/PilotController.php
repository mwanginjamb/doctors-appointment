<?php

namespace backend\controllers;

use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\Facility;
use frontend\models\FacilityAgreement;
use frontend\services\FeeRules;
use frontend\services\Pilot;
use frontend\services\StandardTerms;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F28: the pilot, for platform admins. Facilities with their signed agreements, pilot day and
 * standard terms (confirm on the facility's word, approve); each consultant's pilot day, phase and
 * plan (F22), and restarting a pilot started by test bookings. The console's `pilot/*` commands do
 * the same on the server.
 */
class PilotController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'agreement' => ['POST'], 'ended' => ['POST'], 'confirm' => ['POST'], 'approve' => ['POST'],
                    'plan' => ['POST'], 'restart' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Facilities: those taking bookings, and any with an agreement.
     */
    public function actionIndex()
    {
        $withAgreement = FacilityAgreement::find()->select('facility_id')->distinct()->column();
        $facilities = Facility::find()->where(['or', ['active' => 1], ['id' => $withAgreement]])
            ->orderBy(['name' => SORT_ASC])->all();
        $consultants = ConsultantFacility::find()->select(['n' => 'COUNT(*)', 'facility_id'])
            ->groupBy('facility_id')->indexBy('facility_id')->column();

        $rows = [];
        foreach ($facilities as $facility) {
            $rows[] = [
                'facility' => $facility,
                'agreement' => FacilityAgreement::currentFor($facility->id),
                'day' => Pilot::facilityDay($facility->id),
                'terms' => Pilot::termsStatus($facility->id),
                'consultants' => (int) ($consultants[$facility->id] ?? 0),
            ];
        }

        return $this->render('index', ['rows' => $rows, 'feesOn' => FeeRules::enabled()]);
    }

    /**
     * One facility: its agreements (latest first), standard terms and consultants; records a newly
     * signed agreement.
     */
    public function actionFacility($id)
    {
        $facility = $this->findFacility($id);
        $agreements = FacilityAgreement::find()->where(['facility_id' => $facility->id])
            ->orderBy(['signed_on' => SORT_DESC, 'id' => SORT_DESC])->all();
        $form = new FacilityAgreement([
            'facility_id' => $facility->id,
            'version' => FacilityAgreement::VERSIONS[count(FacilityAgreement::VERSIONS) - 1],
            'signed_on' => date('Y-m-d'),
            'walk_in_exempt' => 1,
            'platform_signatory' => $agreements[0]->platform_signatory ?? null,
        ]);

        return $this->render('facility', [
            'facility' => $facility,
            'agreements' => $agreements,
            'current' => $agreements[0] ?? null,
            'form' => $form,
            'consultants' => $this->consultantsAt($facility->id),
            'day' => Pilot::facilityDay($facility->id),
            'terms' => Pilot::termsStatus($facility->id),
        ]);
    }

    /** Records an agreement the facility signed; it becomes the current one. */
    public function actionAgreement($id)
    {
        $facility = $this->findFacility($id);
        $agreement = new FacilityAgreement();
        $agreement->load($this->request->post());
        $agreement->facility_id = $facility->id;
        $agreement->ended_on = null;
        if ($agreement->save()) {
            Yii::$app->session->setFlash('success', "Agreement v{$agreement->version} signed on {$agreement->signed_on} recorded for {$facility->name}.");
        } else {
            Yii::$app->session->setFlash('error', 'The agreement was not recorded: ' . implode(' ', $agreement->getFirstErrors()));
        }

        return $this->redirect(['facility', 'id' => $facility->id]);
    }

    /** Records that the facility left: its current agreement ends on the date given. */
    public function actionEnded($id)
    {
        $facility = $this->findFacility($id);
        $agreement = FacilityAgreement::currentFor($facility->id);
        $date = (string) $this->request->post('ended_on');
        if ($agreement === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            Yii::$app->session->setFlash('error', 'Give the date the facility left.');
        } else {
            $agreement->ended_on = $date;
            $agreement->save(false, ['ended_on', 'updated_at', 'updated_by']);
            Yii::$app->session->setFlash('success', "{$facility->name} recorded as having left on {$date}. No more standard-terms notices go out for it.");
        }

        return $this->redirect(['facility', 'id' => $facility->id]);
    }

    /** Step 1 on the facility's word, when it confirmed by phone or in writing. */
    public function actionConfirm($id)
    {
        $facility = $this->findFacility($id);
        if (StandardTerms::confirm($facility->id, Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "Standard terms confirmed for {$facility->name}. Approve them to apply them.");
        } else {
            Yii::$app->session->setFlash('error', "{$facility->name} has no agreement recorded, or its standard terms are already confirmed.");
        }

        return $this->redirect(['facility', 'id' => $facility->id]);
    }

    /** Step 2: standard terms apply from now; the facility is emailed. */
    public function actionApprove($id)
    {
        $facility = $this->findFacility($id);
        if (StandardTerms::approve($facility->id, Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "Standard terms approved for {$facility->name}; its facility admin and front desk are emailed.");
        } else {
            Yii::$app->session->setFlash('error', "{$facility->name} has not confirmed standard terms, or they are already approved.");
        }

        return $this->redirect($this->back(['facility', 'id' => $facility->id]));
    }

    /**
     * Every consultant's pilot and plan.
     */
    public function actionConsultants()
    {
        return $this->render('consultants', [
            'consultants' => Consultant::find()->with(['user', 'engagements'])
                ->orderBy(['names' => SORT_ASC])->all(),
            'feesOn' => FeeRules::enabled(),
        ]);
    }

    /** F22: changes a consultant's plan and, on Plan A, whether insured patients pay. */
    public function actionPlan($id)
    {
        $consultant = $this->findConsultant($id);
        $plan = (string) $this->request->post('plan');
        $insuredPay = $plan === FeeRules::PLAN_A ? (bool) $this->request->post('insured_pay') : null;
        if (Pilot::setPlan($consultant, $plan, $insuredPay)) {
            Yii::$app->session->setFlash('success', "{$consultant->names}: " . Pilot::planSummary($consultant) . '.');
        } else {
            Yii::$app->session->setFlash('error', 'Choose Plan A or Plan B.');
        }

        return $this->redirect($this->back(['consultants']));
    }

    /** Clears a pilot started by test bookings; it starts again at the consultant's next booking. */
    public function actionRestart($id)
    {
        $consultant = $this->findConsultant($id);
        if (Pilot::restart($consultant)) {
            Yii::$app->session->setFlash('success', "{$consultant->names}'s pilot starts again at their next booking.");
        } else {
            Yii::$app->session->setFlash('error', "{$consultant->names} has no booking yet; their pilot has not started.");
        }

        return $this->redirect($this->back(['consultants']));
    }

    /**
     * @return Consultant[]
     */
    private function consultantsAt($facilityId)
    {
        return Consultant::find()->with('user')
            ->innerJoin(ConsultantFacility::tableName() . ' cf', 'cf.consultant_id = consultant.id')
            ->where(['cf.facility_id' => (int) $facilityId])
            ->orderBy(['consultant.names' => SORT_ASC])->all();
    }

    /** The page the form was on (a path in this app), else $default. */
    private function back($default)
    {
        $return = (string) $this->request->post('return');
        // A path on this site only: not //host or /\host, which browsers read as another site
        if (preg_match('~^/(?![/\\\\])~', $return)) {
            return $return;
        }

        return $default;
    }

    private function findFacility($id)
    {
        $facility = Facility::findOne((int) $id);
        if ($facility === null) {
            throw new NotFoundHttpException('No such facility.');
        }

        return $facility;
    }

    private function findConsultant($id)
    {
        $consultant = Consultant::findOne((int) $id);
        if ($consultant === null) {
            throw new NotFoundHttpException('No such consultant.');
        }

        return $consultant;
    }
}
