<?php

use frontend\models\BookingLink;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var BookingLink[] $links */
/** @var frontend\models\Consultant[] $consultants who the user may send links for */

$this->title = 'Booking links';
$this->params['breadcrumbs'][] = $this->title;

$badges = [
    BookingLink::STATUS_ACTIVE => ['Active', 'text-bg-success'],
    BookingLink::STATUS_USED => ['Booked', 'text-bg-primary'],
    BookingLink::STATUS_EXPIRED => ['Expired', 'text-bg-secondary'],
    BookingLink::STATUS_REVOKED => ['Revoked', 'text-bg-dark'],
];
$f = Yii::$app->formatter;
?>
<div class="booking-link-index container my-4">

    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">Links you sent to potential patients to book as remote walk-ins. A link works for <?= BookingLink::ttlMinutes() ?> minutes and books once.</p>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($consultants as $consultant): ?>
            <?= Html::a('Send a link for ' . Html::encode($consultant->names), ['create', 'cid' => $consultant->id], ['class' => 'btn btn-sm btn-primary']) ?>
        <?php endforeach; ?>
    </div>

    <table class="table table-sm align-middle">
        <thead>
            <tr><th>Sent</th><th>To</th><th>Consultant</th><th>Slots offered</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($links as $link): ?>
                <?php [$label, $class] = $badges[$link->getStatus()]; ?>
                <tr>
                    <td class="text-nowrap"><?= Html::encode($f->asDatetime($link->created_at, 'php:D j M, H:i')) ?></td>
                    <td>
                        <?= Html::encode($link->recipient_name) ?>
                        <div class="small text-muted"><?= Html::encode(implode(' · ', array_filter([$link->recipient_phone, $link->recipient_email]))) ?></div>
                    </td>
                    <td><?= Html::encode($link->consultant->names ?? '') ?></td>
                    <td class="small">
                        <?= Html::encode($f->asDate(strtotime($link->from_date), 'php:D j M') . ($link->to_date !== $link->from_date ? ' – ' . $f->asDate(strtotime($link->to_date), 'php:D j M') : '')) ?>
                        <?= $link->day_start ? Html::encode(', ' . substr($link->day_start, 0, 5) . '–' . substr($link->day_end, 0, 5)) : '' ?>
                    </td>
                    <td>
                        <span class="badge <?= $class ?>"><?= $label ?></span>
                        <?php if ($link->appointment): ?>
                            <?= Html::a('appointment', ['/appointments/view', 'id' => $link->appointment_id], ['class' => 'small ms-1']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($link->isUsable()): ?>
                            <?= Html::a('Revoke', ['revoke', 'id' => $link->id], [
                                'class' => 'btn btn-sm btn-outline-danger',
                                'data' => ['method' => 'post', 'confirm' => 'Stop this link from working?'],
                            ]) ?>
                        <?php else: ?>
                            <?= Html::a('Send again', ['create', 'cid' => $link->consultant_id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$links): ?>
                <tr><td colspan="6" class="text-muted">No links sent yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
