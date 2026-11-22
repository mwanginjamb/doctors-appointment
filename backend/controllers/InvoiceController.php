<?php

namespace backend\controllers;

use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use frontend\services\Invoices;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F25: consultants' monthly invoices. Issued on the 1st by `php yii invoice/issue`; here a platform
 * admin reads them, records a payment with its reference, waives a line made in error, voids an
 * invoice, or issues last month's now.
 */
class InvoiceController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['issue' => ['POST'], 'record' => ['POST'], 'waive' => ['POST'], 'void' => ['POST']],
            ],
        ];
    }

    public function actionIndex($status = '', $month = '')
    {
        $query = ConsultantInvoice::find()->with('consultant')->orderBy(['period_start' => SORT_DESC, 'id' => SORT_DESC]);
        if ($status === 'overdue') {
            $query->andWhere(['status' => ConsultantInvoice::STATUS_ISSUED])->andWhere(['<', 'due_on', date('Y-m-d')]);
        } elseif ($status !== '') {
            $query->andWhere(['status' => $status]);
        }
        if (preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            $query->andWhere(['period_start' => $month . '-01']);
        }

        return $this->render('index', [
            'dataProvider' => new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 50], 'sort' => false]),
            'status' => $status,
            'month' => $month,
            'enabled' => Invoices::enabled(),
        ]);
    }

    public function actionView($id)
    {
        $invoice = $this->findInvoice($id);

        return $this->render('view', ['invoice' => $invoice, 'lines' => $invoice->getLines()->with('facility')->all()]);
    }

    /** Issues last month's invoices now, as the timer does on the 1st. */
    public function actionIssue()
    {
        $done = Invoices::issueMonth();
        Yii::$app->session->setFlash(Invoices::enabled() ? 'success' : 'warning', Invoices::enabled()
            ? count($done) . ' invoices issued for ' . date('F Y', strtotime('first day of last month')) . '.'
            : reset($done));

        return $this->redirect(['index']);
    }

    public function actionRecord($id)
    {
        $invoice = $this->findInvoice($id);
        if (Invoices::markPaid($invoice, $this->request->post('reference'), Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "{$invoice->number} recorded as paid ({$invoice->reference}).");
        } else {
            Yii::$app->session->setFlash('error', 'Enter the M-Pesa or bank reference (letters and numbers), on an unpaid invoice.');
        }

        return $this->redirect(['view', 'id' => $invoice->id]);
    }

    public function actionWaive($id)
    {
        $line = ConsultantInvoiceLine::findOne((int) $id);
        if ($line === null || $line->invoice_id === null) {
            throw new NotFoundHttpException('No such invoice line.');
        }
        if (Invoices::waive($line, $this->request->post('reason'))) {
            Yii::$app->session->setFlash('success', 'Line waived; the invoice is now Ksh ' . number_format($line->invoice->amount) . '.');
        } else {
            Yii::$app->session->setFlash('error', 'Say why the line is waived. Lines on a paid or void invoice cannot be waived.');
        }

        return $this->redirect(['view', 'id' => $line->invoice_id]);
    }

    public function actionVoid($id)
    {
        $invoice = $this->findInvoice($id);
        if (Invoices::void($invoice, $this->request->post('reason'), Yii::$app->user->id)) {
            Yii::$app->session->setFlash('success', "{$invoice->number} is void; nothing is due on it.");
        } else {
            Yii::$app->session->setFlash('error', 'Say why the invoice is void. Only an unpaid invoice can be voided.');
        }

        return $this->redirect(['view', 'id' => $invoice->id]);
    }

    private function findInvoice($id)
    {
        $invoice = ConsultantInvoice::findOne((int) $id);
        if ($invoice === null) {
            throw new NotFoundHttpException('No such invoice.');
        }

        return $invoice;
    }
}
