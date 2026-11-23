<?php

namespace frontend\controllers;

use frontend\services\Clashes;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * F14: the bookings that clash with a facility closure, a consultant's time off or a public
 * holiday (/clash/<type>/<id>), for whoever handles them, and "Tell the patients".
 */
class ClashController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => ['class' => AccessControl::class, 'rules' => [['allow' => true, 'roles' => ['@']]]],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['tell' => ['POST']]],
        ];
    }

    public function actionView($type, $id)
    {
        $source = $this->findSource($type, $id);
        $rows = Clashes::rowsFor($source);
        if (!$rows && !Clashes::canManageSource($source)) {
            throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        }

        return $this->render('view', [
            'source' => $source,
            'type' => $type,
            'rows' => $rows,
            'untold' => count(array_filter($rows, function ($r) { return $r['open'] && ($r['clash'] === null || !$r['clash']->told_at); })),
        ]);
    }

    public function actionTell($type, $id)
    {
        $source = $this->findSource($type, $id);
        $ids = Yii::$app->request->post('appointments');
        $n = Clashes::tell($source, (string) Yii::$app->request->post('message', ''), is_array($ids) ? $ids : null);
        Yii::$app->session->setFlash($n ? 'success' : 'info', $n
            ? Yii::t('app', '{n, plural, one{# patient was} other{# patients were}} told, with a link to choose a new time.', ['n' => $n])
            : Yii::t('app', 'Nobody new to tell.'));

        return $this->redirect(['view', 'type' => $type, 'id' => $id]);
    }

    private function findSource($type, $id)
    {
        $source = Clashes::source($type, $id);
        if ($source === null) {
            throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        }

        return $source;
    }
}
