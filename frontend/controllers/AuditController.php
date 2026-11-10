<?php

namespace frontend\controllers;

use common\components\RbacHelper;
use common\models\AuditLog;
use frontend\components\AdminAccessControl;
use frontend\models\AuditLogSearch;
use yii\web\Controller;

/**
 * The audit log, read-only: who changed what on appointments, accounts and the clinic's settings,
 * and who signed in. Entries are never edited or deleted here.
 */
class AuditController extends Controller
{
    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AdminAccessControl::class,
                'permission' => RbacHelper::PERM_VIEW_AUDIT_LOG,
            ],
        ];
    }

    public function actionIndex()
    {
        $search = new AuditLogSearch();
        $dataProvider = $search->search($this->request->queryParams);

        return $this->render('index', [
            'search' => $search,
            'dataProvider' => $dataProvider,
            'retentionYears' => AuditLog::retentionYears(),
        ]);
    }
}
