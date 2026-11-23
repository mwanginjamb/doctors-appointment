<?php

namespace frontend\controllers;

use frontend\models\Consultant;
use frontend\services\PilotReport;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * F26: the pilot report on the main site, read only. A facility admin sees their facility (every
 * consultant there, the total and the ROI estimate); a consultant sees their own figures at each
 * facility they work at. Platform admins see every facility in /admin.
 */
class PilotReportController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => ['class' => AccessControl::class, 'rules' => [['allow' => true, 'roles' => ['@']]]],
        ];
    }

    public function actionIndex($facility = null, $format = null)
    {
        $identity = Yii::$app->user->identity;
        if ($identity->isFacilityAdminAccount()) {
            $only = null; // everyone at their facility
            $ids = $identity->facility_id ? [(int) $identity->facility_id] : [];
        } elseif ($identity->isConsultantAccount()) {
            $consultant = Consultant::findOne(['user_id' => $identity->id]);
            $only = $consultant ? [(int) $consultant->id] : [];
            $ids = \frontend\services\Pilot::facilitiesOf($identity);
        } else {
            throw new ForbiddenHttpException(Yii::t('app', 'The pilot report is for facility admins and consultants.'));
        }

        $reports = [];
        foreach (PilotReport::facilities($ids) as $f) {
            if ($facility !== null && (int) $f->id !== (int) $facility) {
                continue;
            }
            $report = PilotReport::forFacility($f, $only);
            if ($report['consultants']) {
                $reports[] = $report;
            }
        }
        if ($format === 'csv') {
            if (count($reports) !== 1) {
                throw new NotFoundHttpException(Yii::t('app', 'Choose a facility to download.'));
            }

            return Yii::$app->response->sendContentAsFile(PilotReport::csv($reports[0]), PilotReport::csvName($reports[0]['facility']), ['mimeType' => 'text/csv']);
        }

        return $this->render('index', ['reports' => $reports, 'mine' => $only !== null]);
    }
}
