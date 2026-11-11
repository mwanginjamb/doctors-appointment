<?php

use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\RemoteWalkInForm $form */
/** @var frontend\models\BookingLink $link */

$appointment = $form->appointment;
$this->title = 'Appointment booked';
?>
<div class="book-booked container my-5" style="max-width: 40rem">
    <div class="alert alert-success">
        <h1 class="h4 fw-bold">Your appointment is booked</h1>
        <p class="mb-1">
            <?= Html::encode($link->consultant->names) ?>,
            <strong><?= Html::encode(date('l j F Y \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time))) ?></strong>
            (<?= (int) $appointment->duration_minutes ?> minutes<?= $appointment->getFeeLabel() ? ', ' . Html::encode($appointment->getFeeLabel()) : '' ?>).
        </p>
        <div class="mb-2"><?= $this->render('//facility/_place', ['facility' => $appointment->facility, 'fallback' => null]) ?></div>
        <p class="mb-0">
            A confirmation is on its way<?= $link->recipient_phone || $form->phone ? ' by WhatsApp' : '' ?><?= $form->email ? ($link->recipient_phone || $form->phone ? ' and email' : ' by email') : '' ?>.
            Please arrive 15 minutes early. To change or cancel it, contact the clinic.
        </p>
    </div>
</div>
