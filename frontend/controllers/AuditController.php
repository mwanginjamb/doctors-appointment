<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use common\models\AuditLog;
use frontend\components\FrontDesk;
use frontend\models\AuditLogSearch;
use frontend\models\Facility;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * The audit log, read-only: who changed what on appointments, accounts and the clinic's settings,
 * and who signed in. Entries are never edited or deleted here.
 *
 * A facility admin reads only the entries recorded against their facility (permission
 * viewFacilityAuditLog, rule facilityAdmin).
 */
class AuditController extends Controller
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
                        return Yii::$app->user->can(RbacHelper::PERM_VIEW_AUDIT_LOG) || $this->ownFacilityId() !== null;
                    },
                ]],
            ],
        ];
    }

    /**
     * The facility whose entries a facility admin may read, or null when they may not (or the user
     * reads the whole log).
     */
    private function ownFacilityId()
    {
        if (!FrontDesk::isFacilityAdmin()) {
            return null;
        }
        $id = FrontDesk::facilityId();

        return Yii::$app->user->can(RbacHelper::PERM_VIEW_FACILITY_AUDIT_LOG, ['facilityId' => $id]) ? $id : null;
    }

    public function actionIndex()
    {
        $search = new AuditLogSearch();
        $own = Yii::$app->user->can(RbacHelper::PERM_VIEW_AUDIT_LOG) ? null : $this->ownFacilityId();
        $search->lockedFacilityId = $own;
        $dataProvider = $search->search($this->request->queryParams);

        return $this->render('index', [
            'search' => $search,
            'dataProvider' => $dataProvider,
            'retentionYears' => AuditLog::retentionYears(),
            // F8 phase 2: entries can be read per facility
            'facilities' => Facility::find()->select(['name', 'id'])->andFilterWhere(['id' => $own])->orderBy(['name' => SORT_ASC])->indexBy('id')->column(),
        ]);
    }
}
