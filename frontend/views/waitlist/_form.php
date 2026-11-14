<?php

use frontend\models\WaitlistForm;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * The waiting-list form, for patients joining and staff adding a patient.
 *
 * @var yii\web\View $this
 * @var WaitlistForm $form
 * @var string $submit button label
 */

$choices = $form->facilityChoices();
?>
<?php $f = ActiveForm::begin(['id' => 'waitlist-form']); ?>
    <?php if ($form->byStaff && !$form->appointment): ?>
        <?= $f->field($form, 'patient_lookup')->textInput(['maxlength' => 255, 'autocomplete' => 'off'])
            ->hint('An existing patient. To add someone new, register them from the calendar first.') ?>
    <?php endif; ?>

    <?php if ($form->appointment): ?>
        <p class="mb-3" id="waitlist-earlier-note">
            Any time earlier than
            <strong><?= Html::encode(date('l j F \a\t H:i', strtotime($form->appointment->date . ' ' . $form->appointment->time))) ?></strong>
            at <?= Html::encode($form->appointment->facility->name ?? 'the same facility') ?>.
            Choosing one moves the appointment, and frees its current time for someone else.
        </p>
    <?php elseif (!$choices): ?>
        <div class="alert alert-warning">
            <?= Html::encode($form->consultant->names) ?> is not taking online bookings anywhere right now, so there is no waiting list to join. Please call the clinic.
        </div>
    <?php else: ?>
        <?= count($choices) > 1
            ? $f->field($form, 'facility_id')->dropDownList($choices, ['prompt' => 'Choose a facility'])
            : $f->field($form, 'facility_id')->hiddenInput()->label(false) . Html::tag('p', 'At <strong>' . Html::encode(reset($choices)) . '</strong>.') ?>
        <div class="row g-2">
            <div class="col-sm-6"><?= $f->field($form, 'from_date')->input('date', ['min' => date('Y-m-d')]) ?></div>
            <div class="col-sm-6"><?= $f->field($form, 'to_date')->input('date', ['min' => date('Y-m-d')])
                ->hint('Up to ' . \frontend\services\Waitlist::maxDays() . ' days.') ?></div>
        </div>
    <?php endif; ?>

    <?php if ($form->appointment || $choices): ?>
        <?= $f->field($form, 'part_of_day')->radioList(WaitlistForm::partOfDayChoices()) ?>
        <?= Html::submitButton($submit, ['class' => 'btn btn-primary', 'id' => 'waitlist-submit']) ?>
    <?php endif; ?>
<?php ActiveForm::end(); ?>
