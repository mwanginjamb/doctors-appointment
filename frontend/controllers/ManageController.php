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
                // POST only: opening the link (or WhatsApp's link preview fetching it) never changes anything
                'actions' => ['cancel' => ['POST'], 'reschedule' => ['POST'], 'confirm' => ['POST'], 'waitlist' => ['POST']],
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

    /**
     * The patient confirms they are coming: the appointment becomes confirmed, as when the
     * consultant confirms it. Possible until the appointment starts (it is not a change, so the
     * cut-off does not apply).
     */
    public function actionConfirm($id, $sig)
    {
        $this->throttle('manage');
        $appointment = SelfServiceLink::verify($id, $sig);
        if ($appointment === null) {
            return $this->render('invalid');
        }
        $this->actAsPatient($appointment);
        if ($appointment->status === Appointments::STATUS_CONFIRMED) {
            Yii::$app->session->setFlash('info', Yii::t('app', 'Your appointment is already confirmed. See you then.'));
        } elseif (!SelfServiceLink::canConfirm($appointment)) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'This appointment can no longer be confirmed.'));
        } else {
            $appointment->status = Appointments::STATUS_CONFIRMED;
            // F13: confirmed by the patient, from their link
            $appointment->confirmed_via = Appointments::CONFIRMED_LINK;
            $appointment->confirmed_by = $appointment->patient_id;
            $appointment->confirmed_at = time();
            $appointment->save(false, ['status', 'confirmed_via', 'confirmed_by', 'confirmed_at', 'updated_at', 'updated_by']);
            Yii::$app->session->setFlash('success', Yii::t('app', 'Thank you: your appointment is confirmed. See you then.'));
        }

        return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
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
     * F4: "Tell me if an earlier time opens": the patient waits for a time before this appointment,
     * at the same facility (any time of day), and is taken to their waiting-list page.
     */
    public function actionWaitlist($id, $sig)
    {
        $this->throttle('manage');
        $appointment = SelfServiceLink::verify($id, $sig);
        if ($appointment === null) {
            return $this->render('invalid');
        }
        if (!\frontend\services\Waitlist::isEnabled() || !$appointment->canBeCancelled()) {
            Yii::$app->session->setFlash('error', Yii::t('app', 'This appointment can no longer be moved.'));
            return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
        }
        $this->actAsPatient($appointment);
        $form = new \frontend\models\WaitlistForm(['appointment' => $appointment, 'patient' => $appointment->patient]);
        $form->part_of_day = (string) $this->request->post('part_of_day', 'any');
        if (!$form->save()) {
            $errors = $form->getFirstErrors();
            Yii::$app->session->setFlash('error', \yii\helpers\Html::encode($errors ? reset($errors) : Yii::t('app', 'Could not add you to the waiting list.')));
            return $this->redirect(['index', 'id' => $id, 'sig' => $sig]);
        }
        Yii::$app->session->setFlash('success', Yii::t('app', 'Done: we will message you if an earlier time opens. Your appointment stays as it is until you choose one.'));

        return $this->redirect(['/waitlist/offer', 'id' => $form->entry->id, 'sig' => $form->entry->signature()]);
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
