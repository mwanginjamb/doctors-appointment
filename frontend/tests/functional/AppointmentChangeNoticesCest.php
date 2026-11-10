<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\models\Appointments;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

/**
 * F1: when an appointment is cancelled or moved, the other side is told by email and WhatsApp.
 */
class AppointmentChangeNoticesCest
{
    use BookingDataTrait;

    /** @var Appointments */
    private $appointment;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->appointment = $this->book('10:00');
    }

    /**
     * Queued jobs of $class whose serialized payload contains every string in $contains.
     */
    private function queued($class, array $contains = [])
    {
        $query = (new Query())->from('{{%queue}}')->where(['like', 'job', $class]);
        foreach ($contains as $text) {
            $query->andWhere(['like', 'job', $text]);
        }

        return (int) $query->count();
    }

    private function snapshot()
    {
        return [
            'patientEmail' => $this->queued('SendEmailMessageJob', ['client@example.com']),
            'patientWhatsapp' => $this->queued('SendWhatsappTextJob', ['0712345678']),
            'doctorEmail' => $this->queued('SendEmailMessageJob', ['doctor@example.com']),
            'doctorWhatsapp' => $this->queued('SendWhatsappTextJob', ['0712000000']),
            'oldRescheduleEmail' => $this->queued('SendReminderEmailJob', ['Reschedule']),
        ];
    }

    private function delta(array $before)
    {
        $after = $this->snapshot();
        return array_map(function ($key) use ($before, $after) {
            return $after[$key] - $before[$key];
        }, array_combine(array_keys($after), array_keys($after)));
    }

    public function theConsultantCancellingTellsThePatient(FunctionalTester $I)
    {
        $before = $this->snapshot();
        $I->amLoggedInAs($this->consultantUser);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $I->assertSame(['patientEmail' => 1, 'patientWhatsapp' => 1, 'doctorEmail' => 0, 'doctorWhatsapp' => 0, 'oldRescheduleEmail' => 0], $this->delta($before));
        $I->assertSame(1, $this->queued('SendEmailMessageJob', ['client@example.com', 'is cancelled']));
    }

    public function thePatientCancellingTellsTheConsultant(FunctionalTester $I)
    {
        $before = $this->snapshot();
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $I->assertSame(['patientEmail' => 0, 'patientWhatsapp' => 0, 'doctorEmail' => 1, 'doctorWhatsapp' => 1, 'oldRescheduleEmail' => 0], $this->delta($before));
        $I->assertSame(1, $this->queued('SendEmailMessageJob', ['doctor@example.com', 'cancelled by Client']));
    }

    public function anAdminCancellingTellsThePatient(FunctionalTester $I)
    {
        $before = $this->snapshot();
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->submitForm('#cancel-appointment-form', ['note' => 'Doctor unwell']);

        $delta = $this->delta($before);
        $I->assertSame(1, $delta['patientEmail']);
        $I->assertSame(1, $delta['patientWhatsapp']);
        $I->assertSame(0, $this->queued('SendEmailMessageJob', ['Doctor unwell']), 'the internal note is not sent');
    }

    public function theClinicMovingItTellsThePatientWithOldAndNewTimes(FunctionalTester $I)
    {
        $before = $this->snapshot();
        $I->amLoggedInAs($this->consultantUser);
        $moved = $I->sendJsonPost(['/api/update-visit'], ['id' => $this->appointment->id, 'date' => $this->date, 'time' => '14:00:00']);
        $I->assertSame('success', $moved['status'], json_encode($moved));

        $I->assertSame(['patientEmail' => 1, 'patientWhatsapp' => 1, 'doctorEmail' => 0, 'doctorWhatsapp' => 0, 'oldRescheduleEmail' => 0], $this->delta($before));
        $I->assertSame(1, $this->queued('SendEmailMessageJob', ['client@example.com', 'at 10:00 to', 'at 14:00']));
    }

    public function thePatientMovingItTellsTheConsultant(FunctionalTester $I)
    {
        $before = $this->snapshot();
        $I->amLoggedInAs($this->client);
        $moved = $I->sendJsonPost(['/api/update-visit'], ['id' => $this->appointment->id, 'date' => $this->date, 'time' => '14:00:00']);
        $I->assertSame('success', $moved['status'], json_encode($moved));

        $delta = $this->delta($before);
        $I->assertSame(1, $delta['doctorEmail']);
        $I->assertSame(1, $delta['doctorWhatsapp']);
        $I->assertSame(0, $delta['patientEmail']);
    }

    public function aPatientWithoutEmailIsToldByWhatsappOnly(FunctionalTester $I)
    {
        User::updateAll(['email' => null], ['id' => $this->client->id]);
        $emailsBefore = $this->queued('SendEmailMessageJob');
        $before = $this->snapshot();

        $I->amLoggedInAs($this->consultantUser);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $I->assertSame($emailsBefore, $this->queued('SendEmailMessageJob'));
        $I->assertSame(1, $this->delta($before)['patientWhatsapp']);
    }

    public function editingOtherDetailsSendsNothing(FunctionalTester $I)
    {
        $before = $this->snapshot();
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/update', ['id' => $this->appointment->id]);
        $I->submitForm('.appointments-form form', ['Appointments[symptoms_brief]' => 'Updated notes']);

        $I->assertSame(array_fill_keys(array_keys($before), 0), $this->delta($before));
    }
}
