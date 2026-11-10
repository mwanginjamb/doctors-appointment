<?php

use yii\bootstrap5\Html;

/**
 * A self-service link that does not match an appointment as it is now: wrong, or the appointment
 * has been moved since the message was sent (each time gets its own link).
 *
 * @var yii\web\View $this
 */

$this->title = 'This link is no longer valid';
?>
<div class="manage-invalid container my-5" style="max-width: 40rem">
    <div class="alert alert-warning">
        <h1 class="h4 fw-bold"><?= Html::encode($this->title) ?></h1>
        <p class="mb-0">Your appointment may have been moved since this message was sent. Use the link in your most recent message, or contact the clinic.</p>
    </div>
</div>
