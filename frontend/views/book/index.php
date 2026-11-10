<?php

use frontend\components\SlotPicker;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * Public booking page a booking link opens. Only the free slots the link offers can be chosen;
 * days outside its range cannot be reached, and hours outside the slots are greyed out.
 *
 * @var yii\web\View $this
 * @var frontend\models\BookingLink $link
 * @var frontend\models\RemoteWalkInForm $form
 */

$consultant = $link->consultant;
$length = $consultant->getSessionMinutes();
$slots = $link->openSlots();
// Days in the link's range with at least one free time
$hasSlots = (bool) array_filter($slots);

$this->title = 'Book with ' . $consultant->names;
?>
<div class="book-index container my-4">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted mb-1">
        <?= Html::encode(Yii::$app->name) ?> invited you to book an appointment of <?= $length ?> minutes.
        Choose one of the green times, then confirm your details.
    </p>
    <p class="small text-muted">This link works until <strong><?= date('H:i', $link->expires_at) ?></strong> and books one appointment.</p>

    <?php if (!$hasSlots): ?>
        <div class="alert alert-warning">There are no free times left in this link's range. Please contact the clinic for another time.</div>
    <?php else: ?>
        <?= SlotPicker::widget([
            'slots' => $slots,
            'minutes' => $length,
            'eventTitle' => 'Available',
            'from' => $link->from_date,
            'to' => $link->to_date,
            'targets' => [
                'dateInput' => Html::getInputId($form, 'date'),
                'timeInput' => Html::getInputId($form, 'time'),
                'label' => 'selected-slot',
                'button' => 'book-button',
                'scrollTo' => 'booking-details',
            ],
            'labelPrefix' => 'Your time: ',
            'options' => ['class' => 'mb-4'],
        ]) ?>

        <div class="card shadow-sm" id="booking-details">
            <div class="card-body">
                <h2 class="h5 fw-bold">Your details</h2>
                <p id="selected-slot" class="fw-semibold text-primary mb-3">Choose a time above.</p>

                <?php $f = ActiveForm::begin(['id' => 'remote-walk-in-form']); ?>
                <?= Html::activeHiddenInput($form, 'date') ?>
                <?= Html::activeHiddenInput($form, 'time') ?>
                <?= $f->field($form, 'time', ['template' => '{error}'])->label(false) ?>
                <div class="row g-2">
                    <div class="col-md-6"><?= $f->field($form, 'full_name')->textInput(['maxlength' => 150]) ?></div>
                    <div class="col-md-6"><?= $f->field($form, 'phone')->input('tel', ['maxlength' => 20, 'readonly' => $form->isLocked('phone')]) ?></div>
                    <div class="col-md-6"><?= $f->field($form, 'email')->input('email', ['maxlength' => 255, 'readonly' => $form->isLocked('email')]) ?></div>
                </div>
                <?= $f->field($form, 'reason')->textarea(['rows' => 2, 'maxlength' => 500]) ?>
                <?= Html::submitButton('Book this time', ['class' => 'btn btn-primary', 'id' => 'book-button', 'disabled' => !$form->time]) ?>
                <?php ActiveForm::end(); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
