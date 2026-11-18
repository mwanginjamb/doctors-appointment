<?php

namespace backend\controllers;

use frontend\models\CommitmentFee;
use frontend\models\Consultant;
use frontend\models\Facility;
use frontend\models\PatientCredit;
use frontend\services\FeeRules;
use yii\data\ActiveDataProvider;
use yii\web\Controller;

/**
 * F28: the money side of F22–F23, read-only. Commitment fees (paid, absorbed, forfeited,
 * credited, refunded…) with their M-Pesa receipts, and patients' rebooking credits. Refunds are
 * decided on the Refunds page.
 */
class PaymentController extends Controller
{
    public function actionIndex($state = '', $facility = '', $consultant = '', $from = '', $to = '')
    {
        $query = CommitmentFee::find()->alias('f')
            ->with(['patient', 'consultant', 'facility', 'transaction', 'appointment']);
        $query->andFilterWhere(['f.state' => $state])
            ->andFilterWhere(['f.facility_id' => $facility === '' ? null : (int) $facility])
            ->andFilterWhere(['f.consultant_id' => $consultant === '' ? null : (int) $consultant]);
        $from = $this->date($from);
        $to = $this->date($to);
        $query->andFilterWhere(['>=', 'f.fee_date', $from])->andFilterWhere(['<=', 'f.fee_date', $to]);

        // Totals by state for the same filters
        $totals = (clone $query)->select(['f.state', 'n' => 'COUNT(*)', 'ksh' => 'SUM(f.amount)'])
            ->with([])->groupBy('f.state')->orderBy(null)->asArray()->all();

        return $this->render('index', [
            'dataProvider' => new ActiveDataProvider([
                'query' => $query->orderBy(['f.id' => SORT_DESC]),
                'pagination' => ['pageSize' => 50],
                'sort' => false,
            ]),
            'totals' => $totals,
            'filters' => compact('state', 'facility', 'consultant', 'from', 'to'),
            'facilities' => Facility::find()->select(['name', 'id'])->orderBy('name')->indexBy('id')->column(),
            'consultants' => Consultant::find()->select(['names', 'user_id'])->where(['not', ['user_id' => null]])
                ->orderBy('names')->indexBy('user_id')->column(),
            'feesOn' => FeeRules::enabled(),
        ]);
    }

    public function actionCredits($status = '')
    {
        $query = PatientCredit::find()->with(['patient', 'sourceFee.appointment.consultant'])
            ->andFilterWhere(['status' => $status])
            ->orderBy(['id' => SORT_DESC]);

        return $this->render('credits', [
            'dataProvider' => new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50], 'sort' => false]),
            'status' => $status,
        ]);
    }

    /** A Y-m-d date, or null. */
    private function date($value)
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
