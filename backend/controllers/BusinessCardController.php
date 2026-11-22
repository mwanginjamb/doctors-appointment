<?php

namespace backend\controllers;

use frontend\models\Consultant;
use frontend\services\BusinessCard;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * F29: a consultant's business card for platform admins: see and print it, download its QR code,
 * and choose the facilities on it, as the consultant does on /business-card.
 */
class BusinessCardController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['facilities' => ['POST']]],
        ];
    }

    public function actionView($id)
    {
        $consultant = $this->findConsultant($id);

        return $this->render('view', [
            'consultant' => $consultant,
            'facilities' => BusinessCard::facilities($consultant),
            'allFacilities' => BusinessCard::activeFacilities($consultant),
        ]);
    }

    public function actionFacilities($id)
    {
        $consultant = $this->findConsultant($id);
        $problem = BusinessCard::chooseFacilities($consultant, (array) $this->request->post('facilities', []));
        Yii::$app->session->setFlash($problem === null ? 'success' : 'error', $problem ?? "{$consultant->names}'s card is updated.");

        return $this->redirect(['view', 'id' => $consultant->id]);
    }

    public function actionPrint($id)
    {
        $consultant = $this->findConsultant($id);

        return $this->renderPartial('@frontend/views/business-card/print', ['consultant' => $consultant, 'facilities' => BusinessCard::facilities($consultant)]);
    }

    public function actionQr($id, $format = 'svg')
    {
        $consultant = $this->findConsultant($id);
        $png = $format === 'png';
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', $png ? 'image/png' : 'image/svg+xml');
        $response->headers->set('Content-Disposition', 'attachment; filename="qr-' . $consultant->profileSlug() . ($png ? '.png' : '.svg') . '"');
        $out = BusinessCard::qr($consultant->profileUrl(true), $png ? 'png' : 'svg');

        return $png ? $out : '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
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
