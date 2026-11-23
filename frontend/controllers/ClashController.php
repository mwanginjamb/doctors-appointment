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
 * holiday (/clash/<type>/<id>), for whoever handles them: "Tell the patients" (part 1), and moving
 * or cancelling the ticked ones in bulk (part 2).
 */
class ClashController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => ['class' => AccessControl::class, 'rules' => [['allow' => true, 'roles' => ['@']]]],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['tell' => ['POST'], 'move' => ['POST'], 'cancel' => ['POST']]],
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
            // Part 2: where the open ones may be moved
            'facilities' => Clashes::facilityChoices(array_column(array_filter($rows, function ($r) { return $r['open']; }), 'appointment')),
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

    /**
     * Part 2: moves the ticked bookings to another day. First a preview (each booking's planned new
     * time: the first free slots that day, in order, spilling to the following days), then saving
     * exactly those times. A time taken in between leaves that booking as it was, and says so.
     */
    public function actionMove($type, $id)
    {
        $source = $this->findSource($type, $id);
        $request = Yii::$app->request;

        if ($request->post('step') === 'save') {
            $times = [];
            foreach ((array) $request->post('plan', []) as $appointmentId => $when) {
                $parts = explode(' ', (string) $when);
                $times[(int) $appointmentId] = [$parts[0] ?? '', $parts[1] ?? ''];
            }
            $result = Clashes::move($source, $times, (string) $request->post('message', ''));
            $session = Yii::$app->session;
            if ($result['moved']) {
                $session->addFlash('success', Yii::t('app', '{n, plural, one{# booking was} other{# bookings were}} moved; each patient has been told the new time.', ['n' => count($result['moved'])]));
            }
            foreach ($result['failed'] as $appointmentId => $why) {
                $session->addFlash('error', Yii::t('app', 'Booking #{id} was not moved: {why}', ['id' => $appointmentId, 'why' => $why]));
            }

            return $this->redirect(['view', 'type' => $type, 'id' => $id]);
        }

        $appointments = Clashes::pick($source, (array) $request->post('appointments', []));
        $date = (string) $request->post('date', '');
        if (!$appointments || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Tick the bookings to move and choose a day from today on.'));

            return $this->redirect(['view', 'type' => $type, 'id' => $id]);
        }
        $facilityId = (int) $request->post('facility', 0) ?: null;
        if ($facilityId !== null && !array_key_exists($facilityId, Clashes::facilityChoices($appointments))) {
            $facilityId = null;
        }

        return $this->render('move', [
            'source' => $source,
            'type' => $type,
            'date' => $date,
            'facilityId' => $facilityId,
            'plan' => Clashes::planMove($appointments, $date, $facilityId),
        ]);
    }

    /** Part 2: cancels the ticked bookings as the clinic's doing; each patient is told, with a link to book again. */
    public function actionCancel($type, $id)
    {
        $source = $this->findSource($type, $id);
        $appointments = Clashes::pick($source, (array) Yii::$app->request->post('appointments', []));
        if (!$appointments) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'Tick the bookings to cancel.'));

            return $this->redirect(['view', 'type' => $type, 'id' => $id]);
        }
        $n = Clashes::cancel($source, $appointments, (string) Yii::$app->request->post('message', ''));
        Yii::$app->session->setFlash('success', Yii::t('app', '{n, plural, one{# booking was} other{# bookings were}} cancelled; each patient has been told, with a link to book again.', ['n' => $n]));

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
