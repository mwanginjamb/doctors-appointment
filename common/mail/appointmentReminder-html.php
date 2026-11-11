<?php
/** @var $appointment frontend\models\Appointments */
/** @var $timeUnit string */
/** @var $recipientName string */

$patientName = $appointment->patient->full_name ?? 'Patient';
$consultantName = $consultant->names ?? 'Doctor';
// F8: where it takes place is the appointment's facility; older appointments fall back to the profile
$facility = $appointment->facility;
$location = $facility ? ($facility->address ?: $facility->name) : ($consultant->physical_address ?? 'Location not specified');
$practiceName = $facility ? $facility->name : ($consultant->practice_name ?? 'Practice Name Not Specified');
$mapUrl = $facility->map_url ?? null;
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
            <strong>Facility:</strong> <?= htmlspecialchars($practiceName ?? 'Practice Name Not Specified') ?><br>
            <?php if ($appointment->getFeeLabel()): ?>
                <strong>Fee:</strong> <?= htmlspecialchars($appointment->getFeeLabel()) ?><br>
            <?php endif; ?>
            <hr>
            <strong>Appointment Brief:</strong>
            <?= htmlspecialchars(substr($appointment->symptoms_brief, 0, 200)) ?>
            <hr><br>
            <strong>Location:</strong> <?= htmlspecialchars($location) ?>
            <?php if ($mapUrl): ?>
                (<a href="<?= htmlspecialchars($mapUrl) ?>">map</a>)
            <?php endif; ?><br>
            <strong>Patient:</strong> <?= htmlspecialchars($patientName ?? 'Patient') ?>
        </p>


        <div
            style='max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        </div>


        <p>Please ensure to arrive a few minutes early to complete any necessary paperwork.</p>

        <?php if (!empty($manageUrl)): ?>
            <p>Need a different time, or can't make it?</p>
            <p style="margin: 16px 0 24px">
                <a href="<?= htmlspecialchars($manageUrl) ?>"
                    style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Reschedule or cancel</a>
            </p>
        <?php else: ?>
            <p>If you have any questions or need to reschedule, please contact the clinic.</p>
        <?php endif; ?>

        <p>Best regards,<br>Your Healthcare Team</p>

    </div>

</body>

</html>