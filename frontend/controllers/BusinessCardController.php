<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\services\BusinessCard;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * F29: a consultant's business card at /business-card (their own) or /business-card/<consultant id>
 * (for the front desk assigned to them): both sides at real size, printable, with its QR code to
 * download (/business-card/<id>/qr.svg|png). The consultant chooses which facilities it lists.
 */
class BusinessCardController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
        ];
    }

    public function actionIndex($cid = null)
    {
        $user = Yii::$app->user->identity;
        $mine = BusinessCard::consultantsFor($user);
        if ($cid === null) {
            if (count($mine) !== 1) {
                // A front desk working for several consultants picks one; nobody else has a card here
                if (!$mine) {
                    throw new ForbiddenHttpException(Yii::t('app', 'Business cards are for consultants and their front desk.'));
                }

                return $this->render('choose', ['consultants' => $mine]);
            }
            $consultant = $mine[0];
        } else {
            $consultant = $this->findConsultant($cid);
        }
        $canEdit = BusinessCard::canEdit($consultant, $user);

        if (Yii::$app->request->isPost) {
            if (!$canEdit) {
                throw new ForbiddenHttpException(Yii::t('app', 'Only the consultant chooses what is on their card.'));
            }
            $problem = BusinessCard::chooseFacilities($consultant, (array) Yii::$app->request->post('facilities', []));
            Yii::$app->session->setFlash($problem === null ? 'success' : 'error', $problem ?? Yii::t('app', 'Your card is updated.'));

            return $this->redirect(['index', 'cid' => $cid]);
        }

        return $this->render('index', [
            'consultant' => $consultant,
            'facilities' => BusinessCard::facilities($consultant),
            'allFacilities' => BusinessCard::activeFacilities($consultant),
            'canEdit' => $canEdit,
            'others' => count($mine) > 1 ? $mine : [],
        ]);
    }

    /** Both sides, one per 85 × 55 mm page, for "Save as PDF" or a printer. */
    public function actionPrint($cid)
    {
        $consultant = $this->findConsultant($cid);

        return $this->renderPartial('print', ['consultant' => $consultant, 'facilities' => BusinessCard::facilities($consultant)]);
    }

    /** The QR code alone, to download: SVG for print, PNG for posters and messages. */
    public function actionQr($cid, $format = 'svg')
    {
        $consultant = $this->findConsultant($cid);
        $png = $format === 'png';
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', $png ? 'image/png' : 'image/svg+xml');
        $response->headers->set('Content-Disposition', 'attachment; filename="qr-' . $consultant->profileSlug() . ($png ? '.png' : '.svg') . '"');
        $svg = BusinessCard::qr($consultant->profileUrl(true), $png ? 'png' : 'svg');

        return $png ? $svg : '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg;
    }

    private function findConsultant($cid)
    {
        $consultant = Consultant::findOne((int) $cid);
        if ($consultant === null) {
            throw new NotFoundHttpException(Yii::t('app', 'No such consultant.'));
        }
        if (!BusinessCard::canView($consultant, Yii::$app->user->identity)) {
            throw new ForbiddenHttpException(Yii::t('app', 'You cannot open this business card.'));
        }

        return $consultant;
    }
}
