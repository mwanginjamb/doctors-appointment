<?php

namespace frontend\controllers;

use common\components\Audit;
use common\models\AuditLog;
use frontend\components\ThrottlesByIp;
use frontend\models\Appointments;
use frontend\models\SlotFinder;
use frontend\services\BookingService;
use frontend\services\SelfServiceLink;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * The patient's self-service page, opened from the signed link in their reminders and "moved"
 * notices (/manage/<id>/<signature>): reschedule to another free slot with the same consultant, or
 * cancel, up to selfService.cutoffHours before the appointment. No sign-in.
 */
class ManageController extends Controller
{
    use ThrottlesByIp;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['cancel' => ['POST'], 'reschedule' => ['POST']],
            ],
        ];
    }

    public function actionIndex($id, $sig)
    {
        $this->throttle('manage');
        $appointment = SelfServiceLink::verify($id, $sig);
        if ($appointment === null) {
            return $this->render('invalid');
        }

        $changeable = SelfServiceLink::isChangeable($appointment);

        return $this->render('index', [
            'appointment' => $appointment,
            'sig' => $sig,
            'changeable' => $changeable,
            'slots' => $changeable ? $this->freeSlots($appointment) : [],
        ]);
    }

    public function actionCancel($id, $sig)
    {
        $this->throttle('manage');
        $appointment = SelfServiceLink::verify($id, $sig);
        if ($appointment === null) {
            return $this->render('invalid');
        }
        $this->actAsPatient($appointment);
        if (!SelfServiceLink::isChangeable($appointment)) {
            Yii::$app->session->setFlash('error', $this->tooLateMessage());
            return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
        }

        $reason = trim((string) $this->request->post('reason'));
        $appointment->changedByPatient = true; // the consultant is told (F1)
        $appointment->cancel('Cancelled by the patient using their link' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 400) : '.'));
        Yii::$app->session->setFlash('success', Yii::t('app', 'Your appointment is cancelled. The clinic has been told.'));

        return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
    }

    public function actionReschedule($id, $sig)
    {
        $this->throttle('manage');
        $appointment = SelfServiceLink::verify($id, $sig);
        if ($appointment === null) {
            return $this->render('invalid');
        }
        $this->actAsPatient($appointment);
        if (!SelfServiceLink::isChangeable($appointment)) {
            Yii::$app->session->setFlash('error', $this->tooLateMessage());
            return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
        }

        $date = (string) $this->request->post('date');
        $time = substr((string) $this->request->post('time'), 0, 5);
        if (!in_array($time, $this->freeSlots($appointment)[$date] ?? [], true)) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'That time is no longer free. Please choose another.'));
            return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
        }

        // Moved by the patient: the consultant is told (F1)
        if (!Yii::createObject(BookingService::class)->reschedule($appointment, $date, $time . ':00', true)) {
            $errors = $appointment->getFirstErrors();
            Yii::$app->session->setFlash('error', $errors ? reset($errors) : Yii::t('app', 'The appointment could not be moved.'));
            return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
        }

        Yii::$app->session->setFlash('success', Yii::t('app', 'Your appointment is moved. The clinic has been told.'));
        // The link is signed over the time, so the moved appointment has a new one
        return $this->redirect(['index', 'id' => $id, 'sig' => SelfServiceLink::signature($appointment)]);
    }

    /**
     * Free slots with the same consultant at the same facility over the next
     * selfService.rescheduleDays days (within the booking window and minimum notice), ignoring the
     * appointment's own slot.
     */
    private function freeSlots(Appointments $appointment)
    {
        $params = Yii::$app->params;
        $days = min((int) ($params['selfService.rescheduleDays'] ?? 14), (int) ($params['booking.maxDaysAhead'] ?? 90));

        $slots = (new SlotFinder([
            'consultant' => $appointment->consultant,
            'from' => date('Y-m-d'),
            'to' => date('Y-m-d', strtotime('+' . max(0, $days - 1) . ' days')),
            'excludeAppointmentId' => $appointment->id,
            // F8: patients move within the same facility, where they can book themselves
            'facilityId' => $appointment->facility_id,
            'onlineOnly' => true,
            'notBefore' => time() + (int) ($params['booking.minNoticeMinutes'] ?? 0) * 60,
        ]))->find();

        // Its current time is not a "new" time to move to
        if (isset($slots[$appointment->date])) {
            $slots[$appointment->date] = array_values(array_diff($slots[$appointment->date], [substr($appointment->time, 0, 5)]));
        }

        return $slots;
    }

    private function tooLateMessage()
    {
        return Yii::t('app', 'Changes are possible up to {n} hours before the appointment. Please call the clinic.', [
            'n' => SelfServiceLink::cutoffHours(),
        ]);
    }

    /**
     * Changes made through the link are logged as the patient using their self-service link.
     */
    private function actAsPatient(Appointments $appointment)
    {
        $patient = $appointment->patient;
        Audit::actAs(AuditLog::ACTOR_SELF_SERVICE, $appointment->patient_id, $patient ? ($patient->full_name ?: $patient->username) : null);
    }
}
