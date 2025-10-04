<?php
/** @var $appointment frontend\models\Appointments */
/** @var $timeUnit string */
/** @var $recipientName string */

$patientName = $appointment->patient->full_name ?? 'Patient';
$consultantName = $consultant->names ?? 'Doctor';
$location = $consultant->physical_address ?? 'Location not specified';
$practiceName = $consultant->practice_name ?? 'Practice Name Not Specified';
?>

<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
        <h2 style='color: #2c5aa0;'>Appointment Reminder</h2>
        <p>Dear <?= htmlspecialchars($recipientName) ?>,</p>

        <?php if (strpos($timeUnit, 'minute') !== false || strpos($timeUnit, 'hour') !== false): ?>
            <p>This is a friendly reminder that you have an appointment in <strong><?= $timeUnit ?></strong>.</p>
        <?php else: ?>
            <p>This is a friendly reminder that you have an appointment with us.</p>
        <?php endif; ?>

        <p><strong>Date:</strong> <?= Yii::$app->formatter->asDate($appointment->date, 'long') ?><br>
            <strong>Time:</strong> <?= Yii::$app->formatter->asTime($appointment->time, 'short') ?><br>
            <strong>Doctor:</strong> <?= htmlspecialchars($consultantName ?? 'Doctor') ?><br>
            <strong>Practice:</strong> <?= htmlspecialchars($practiceName ?? 'Practice Name Not Specified') ?><br>
            <hr>
            <strong>Appointment Brief:</strong>
            <?= htmlspecialchars(substr($appointment->symptoms_brief, 0, 200)) ?>
            <hr><br>
            <strong>Location:</strong> <?= htmlspecialchars($location) ?><br>
            <strong>Patient:</strong> <?= htmlspecialchars($patientName ?? 'Patient') ?>
        </p>


        <div
            style='max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        </div>


        <p>Please ensure to arrive a few minutes early to complete any necessary paperwork.</p>

        <p>If you have any questions or need to reschedule, feel free do a calendar update.</p>

        <p>Best regards,<br>Your Healthcare Team</p>

    </div>

</body>

</html>