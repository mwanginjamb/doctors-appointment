<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\services\BusinessCard;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F29: a consultant's public profile at /dr/<id>-<name>, the business card's QR code. Anyone can
 * open it without signing in: who they are, where they work (each facility's phone, address and
 * map) and a Book button, which asks for sign-in or registration only then.
 */
class DoctorController extends Controller
{
    public function actionView($id, $slug = null)
    {
        $consultant = Consultant::findOne((int) $id);
        if ($consultant === null || trim((string) $consultant->names) === '') {
            throw new NotFoundHttpException(Yii::t('app', 'This doctor was not found.'));
        }
        // One address per profile: an old or missing name part goes to the current one
        if ($slug !== $consultant->profileSlug()) {
            return $this->redirect($consultant->profileUrl(), 301);
        }

        return $this->render('view', [
            'consultant' => $consultant,
            'facilities' => array_values(BusinessCard::activeFacilities($consultant)),
            'taking' => $consultant->isTakingBookings(),
        ]);
    }
}
