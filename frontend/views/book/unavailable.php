<?php

use frontend\models\BookingLink;
use yii\bootstrap5\Html;

/**
 * Shown when a booking link cannot be used. Says nothing about who it was for.
 *
 * @var yii\web\View $this
 * @var string $reason 'unknown' or a BookingLink status
 */

$messages = [
    BookingLink::STATUS_EXPIRED => ['This booking link has expired', 'Booking links only work for a short time. Please contact the clinic and ask for a new one.'],
    BookingLink::STATUS_USED => ['This booking link has already been used', 'It has booked its appointment. To change it, please contact the clinic.'],
    BookingLink::STATUS_REVOKED => ['This booking link is no longer valid', 'Please contact the clinic and ask for a new one.'],
    'unknown' => ['This booking link is not valid', 'Check that you opened the whole link, or contact the clinic and ask for a new one.'],
];
[$title, $text] = $messages[$reason] ?? $messages['unknown'];
$this->title = $title;
?>
<div class="book-unavailable container my-5" style="max-width: 40rem">
    <div class="alert alert-warning">
        <h1 class="h4 fw-bold"><?= Html::encode($title) ?></h1>
        <p class="mb-0"><?= Html::encode($text) ?></p>
    </div>
</div>
