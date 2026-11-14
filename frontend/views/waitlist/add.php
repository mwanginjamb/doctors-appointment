<?php

use yii\bootstrap5\Html;

/**
 * @var yii\web\View $this
 * @var frontend\models\WaitlistForm $form
 */

$this->title = 'Add to the waiting list: ' . ($form->consultant->names ?? '');
$this->params['breadcrumbs'][] = ['label' => 'Waiting list', 'url' => ['index']];
$this->params['breadcrumbs'][] = 'Add';
?>
<div class="waitlist-add my-4" style="max-width: 40rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <?php if ($form->appointment): ?>
        <p class="text-muted">For <?= Html::encode($form->appointment->patient->full_name ?? 'the patient') ?>, who would like an earlier time.</p>
    <?php endif; ?>
    <p class="text-muted small">
        The patient is sent a link to see free times and to leave the list. When a time frees up, everyone waiting
        for it is told; the first to book it gets it.
    </p>
    <?= $this->render('_form', ['form' => $form, 'submit' => 'Add to the waiting list']) ?>
</div>
