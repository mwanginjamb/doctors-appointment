<?php

use frontend\models\Appointments;
use frontend\models\CommitmentFee;
use yii\bootstrap5\Html;
use yii\helpers\Url;

/**
 * F22: the "check your phone" page while a booking waits for its commitment fee. It asks the server
 * every few seconds and shows the outcome: confirmed, released, or cancelled.
 *
 * @var yii\web\View $this
 * @var Appointments $appointment
 * @var CommitmentFee|null $fee
 * @var array $state CommitmentFees::statusOf()
 * @var string $sig
 * @var int $amount
 */

$consultant = $appointment->consultant;
$when = date('l j F Y \a\t H:i', strtotime($appointment->date . ' ' . $appointment->time));
$this->title = Yii::t('app', 'Pay the commitment fee');
$statusUrl = Url::to(['/fee/status', 'id' => $appointment->id, 'sig' => $sig]);
?>
<div class="fee-pay container my-4" style="max-width: 40rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted mb-4">
        <?= Html::encode(Yii::t('app', '{doctor}, {when}', ['doctor' => $consultant->names ?? Yii::t('app', 'Your doctor'), 'when' => $when])) ?>
        <?= $appointment->facility ? '<br>' . Html::encode($appointment->facility->name) : '' ?>
    </p>

    <div id="pay-waiting" class="card border-0 shadow-sm <?= $appointment->isAwaitingPayment() ? '' : 'd-none' ?>">
        <div class="card-body">
            <h2 class="h5"><?= Yii::t('app', 'Check your phone') ?></h2>
            <p>
                <?= Html::encode(Yii::t('app', 'An M-Pesa prompt for Ksh {amount} was sent to {phone}. Enter your M-Pesa PIN to pay; the time is held for you until then.', [
                    'amount' => number_format($amount),
                    'phone' => $fee && $fee->phone ? '0' . substr($fee->phone, 3, 3) . '***' . substr($fee->phone, -3) : Yii::t('app', 'your phone'),
                ])) ?>
            </p>
            <p class="mb-2"><?= Yii::t('app', 'Time left:') ?> <strong id="pay-countdown">—</strong></p>
            <p id="pay-note" class="small text-danger <?= !empty($state['note']) ? '' : 'd-none' ?>"><?= Html::encode($state['note'] ?? '') ?></p>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?= Html::beginForm(['/fee/resend', 'id' => $appointment->id, 'sig' => $sig], 'post') ?>
                <button type="submit" class="btn btn-outline-primary" id="pay-resend"><?= Yii::t('app', 'Send the prompt again') ?></button>
                <?= Html::endForm() ?>
                <?= Html::beginForm(['/fee/cancel', 'id' => $appointment->id, 'sig' => $sig], 'post', ['onsubmit' => 'return confirm(' . json_encode(Yii::t('app', 'Cancel this booking? The time will be released.')) . ')']) ?>
                <button type="submit" class="btn btn-outline-secondary"><?= Yii::t('app', 'Cancel the booking') ?></button>
                <?= Html::endForm() ?>
            </div>
            <p class="small text-muted mt-3 mb-0"><?= Yii::t('app', 'The fee confirms your booking. It is not refunded if you do not come or cancel less than 24 hours before; it moves with your booking if you reschedule more than 24 hours ahead.') ?></p>
        </div>
    </div>

    <div id="pay-done" class="alert alert-success <?= in_array($appointment->status, [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED, Appointments::STATUS_COMPLETED], true) ? '' : 'd-none' ?>">
        <strong><?= Yii::t('app', 'Paid. Your booking is confirmed.') ?></strong>
        <span id="pay-receipt"><?= !empty($state['receipt']) ? Html::encode(Yii::t('app', 'M-Pesa receipt {receipt}.', ['receipt' => $state['receipt']])) : '' ?></span>
        <?= Yii::t('app', 'A confirmation is on its way.') ?>
    </div>
    <div id="pay-released" class="alert alert-warning <?= $appointment->status === Appointments::STATUS_UNPAID ? '' : 'd-none' ?>">
        <?= Yii::t('app', 'The payment did not arrive in time, so the time was released. Nothing was charged. You can book again.') ?>
    </div>
    <div id="pay-cancelled" class="alert alert-secondary <?= $appointment->status === Appointments::STATUS_CANCELLED ? '' : 'd-none' ?>">
        <?= Yii::t('app', 'This booking was cancelled. Nothing was charged.') ?>
    </div>
</div>
<?php
$initial = json_encode($state);
$this->registerJs(<<<JS
(function () {
    var state = $initial, url = '$statusUrl';
    function show(s) {
        var waiting = s.status === 'awaiting_payment';
        document.getElementById('pay-waiting').classList.toggle('d-none', !waiting);
        document.getElementById('pay-done').classList.toggle('d-none', ['scheduled', 'confirmed', 'completed'].indexOf(s.status) < 0);
        document.getElementById('pay-released').classList.toggle('d-none', s.status !== 'unpaid');
        document.getElementById('pay-cancelled').classList.toggle('d-none', s.status !== 'cancelled');
        var note = document.getElementById('pay-note');
        note.textContent = s.note || '';
        note.classList.toggle('d-none', !s.note);
        if (s.receipt) {
            document.getElementById('pay-receipt').textContent = 'M-Pesa receipt ' + s.receipt + '.';
        }
        state = s;
        return waiting;
    }
    function tick() {
        var left = Math.max(0, state.secondsLeft);
        document.getElementById('pay-countdown').textContent = Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);
        if (state.secondsLeft > 0) { state.secondsLeft--; }
    }
    function poll() {
        fetch(url, {headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (s) {
            if (show(s)) { setTimeout(poll, 4000); }
        }).catch(function () { setTimeout(poll, 8000); });
    }
    if (show(state)) {
        tick();
        setInterval(tick, 1000);
        setTimeout(poll, 4000);
    }
})();
JS);
