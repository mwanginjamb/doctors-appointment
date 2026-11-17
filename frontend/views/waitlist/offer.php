<?php

use frontend\components\SlotPicker;
use frontend\models\WaitlistEntry;
use frontend\services\SelfServiceLink;
use yii\bootstrap5\Html;

/**
 * F4: the page a patient's waiting-list link opens. What they wait for, the free times in their
 * window right now (first to book gets it), and a button to leave the list.
 *
 * @var yii\web\View $this
 * @var WaitlistEntry $entry
 * @var string $sig
 * @var array $slots Y-m-d => 'H:i' start times
 */

$consultant = $entry->consultant;
$doctor = $consultant->names ?? 'the doctor';
$facility = $entry->facility;
$length = (int) $consultant->getSessionMinutes($entry->facility_id);
$earlier = $entry->wantsEarlier();
$hasSlots = (bool) array_filter($slots);

$this->title = 'Waiting list: ' . $doctor;
?>
<div class="waitlist-offer container my-4" style="max-width: 60rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="fs-5 fw-semibold"><?= Html::encode($entry->getWindowLabel()) ?></div>
            <div class="text-muted">
                <?= Html::encode($entry->patient->full_name ?? '') ?> · <?= Html::encode($doctor) ?> · <?= $length ?> minutes
            </div>
            <?= $this->render('//facility/_place', ['facility' => $facility, 'fallback' => null]) ?>
            <?php $labels = WaitlistEntry::statusLabels(); ?>
            <span class="badge mt-2 <?= $entry->isWaiting() ? 'text-bg-primary' : 'text-bg-secondary' ?>" id="waitlist-status">
                <?= Html::encode($entry->isWaiting() ? 'On the waiting list' : $labels[$entry->status]) ?>
            </span>
        </div>
    </div>

    <?php if ($entry->status === WaitlistEntry::STATUS_BOOKED && ($booked = $entry->bookedAppointment)): ?>
        <div class="alert alert-success" id="waitlist-booked">
            Your appointment with <?= Html::encode($doctor) ?> is on
            <strong><?= Html::encode(date('l j F Y \a\t H:i', strtotime($booked->date . ' ' . $booked->time))) ?></strong>.
            Please arrive 15 minutes early.
            <?php if ($manage = SelfServiceLink::urlIfUseful($booked)): ?>
                <div class="mt-2"><?= Html::a('Confirm, move or cancel it', $manage) ?></div>
            <?php endif; ?>
        </div>
    <?php elseif (!$entry->isWaiting()): ?>
        <div class="alert alert-secondary" id="waitlist-ended">
            This waiting list has ended<?= $entry->status === WaitlistEntry::STATUS_LEFT ? ': you left it' : '' ?>.
            You will not get messages about it. To wait again, join from <?= Html::encode($doctor) ?>'s calendar or ask the clinic.
        </div>
    <?php else: ?>
        <h2 class="h5 fw-bold"><?= $earlier ? 'Earlier times free now' : 'Times free now' ?></h2>
        <?php if (!$hasSlots): ?>
            <p class="text-muted" id="waitlist-no-slots">
                Nothing is free right now. We will message you as soon as a time frees up<?= $entry->patient && $entry->patient->email ? ' (by email' . (\common\models\Consent::remindersAllowed($entry->patient_id) && $entry->patient->phone_number ? ' and WhatsApp' : '') . ')' : '' ?>.
            </p>
        <?php else: ?>
            <p class="text-muted small">
                Green times are free. Others on the waiting list may see them too: the first to choose a time gets it.
                <?= $earlier ? 'Choosing one moves your appointment; your current time is kept until then.' : '' ?>
            </p>
            <?= SlotPicker::widget([
                'slots' => $slots,
                'minutes' => $length,
                'weekly' => true,
                'eventTitle' => 'Available',
                'targets' => [
                    'dateInput' => 'waitlist-date',
                    'timeInput' => 'waitlist-time',
                    'label' => 'waitlist-choice',
                    'button' => 'waitlist-book-button',
                ],
                'labelPrefix' => $earlier ? 'New time: ' : 'Your time: ',
                'options' => ['class' => 'mb-3'],
            ]) ?>
            <?= Html::beginForm(['waitlist/book', 'id' => $entry->id, 'sig' => $sig], 'post', ['id' => 'waitlist-book-form', 'class' => 'mb-5']) ?>
                <?= Html::hiddenInput('date', '', ['id' => 'waitlist-date']) ?>
                <?= Html::hiddenInput('time', '', ['id' => 'waitlist-time']) ?>
                <p id="waitlist-choice" class="fw-semibold text-primary">Choose a time above.</p>
                <?php if (!$earlier && \frontend\services\FeeRules::mayCharge($entry->consultant)): ?>
                    <?= $this->render('@frontend/views/fee/_fee_fields', ['idPrefix' => 'waitlist-fee', 'phone' => $entry->patient->phone_number ?? null]) ?>
                <?php endif; ?>
                <?= Html::submitButton($earlier ? 'Move my appointment' : 'Book this time', ['class' => 'btn btn-primary', 'id' => 'waitlist-book-button', 'disabled' => true]) ?>
            <?= Html::endForm() ?>
        <?php endif; ?>

        <h2 class="h5 fw-bold mt-4">Don't need it any more?</h2>
        <?= Html::beginForm(['waitlist/leave', 'id' => $entry->id, 'sig' => $sig], 'post', ['id' => 'waitlist-leave-form']) ?>
            <?= Html::submitButton('Leave the waiting list', ['class' => 'btn btn-outline-secondary']) ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
