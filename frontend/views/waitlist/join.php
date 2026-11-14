<?php

use yii\bootstrap5\Html;

/**
 * @var yii\web\View $this
 * @var frontend\models\WaitlistForm $form
 */

$doctor = $form->consultant->names ?? 'the doctor';
$this->title = $form->appointment ? 'Tell me if something earlier opens' : 'Join the waiting list';
$this->params['breadcrumbs'][] = ['label' => 'My waiting list', 'url' => ['mine']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="waitlist-join my-4" style="max-width: 40rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        When a time with <?= Html::encode($doctor) ?> frees up (someone cancels or moves), we message everyone
        waiting for it, by email and, if you agreed to it, WhatsApp. The first to choose the time gets it.
    </p>
    <?= $this->render('_form', ['form' => $form, 'submit' => $form->appointment ? 'Tell me' : 'Join the waiting list']) ?>
</div>
