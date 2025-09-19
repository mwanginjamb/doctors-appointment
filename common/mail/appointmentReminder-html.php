<?php
/** @var $appointment frontend\models\Appointments */
/** @var $timeUnit string */
/** @var $recipientName string */
?>

<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
        <h2 style='color: #2c5aa0;'>Appointment Reminder</h2>
        <p>Dear <?= htmlspecialchars($recipientName) ?>,</p>
        <p>This is a friendly reminder that you have an appointment in <strong><?= $timeUnit ?></strong>.</p>

        <p><strong>Date:</strong> <?= Yii::$app->formatter->asDate($appointment->date, 'long') ?><br>
            <strong>Time:</strong> <?= Yii::$app->formatter->asTime($appointment->time, 'short') ?><br>
            <strong>Doctor:</strong> <?= htmlspecialchars($appointment->consultant->names ?? 'Doctor') ?><br>
            <strong>Patient:</strong> <?= htmlspecialchars($appointment->patient->full_name ?? 'Patient') ?>
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