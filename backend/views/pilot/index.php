<?php

use frontend\services\Pilot;
use yii\bootstrap5\Html;

/**
 * F28: every facility's agreement, pilot day and standard terms.
 *
 * @var yii\web\View $this
 * @var array[] $rows facility, agreement (current, or null), day, terms, consultants
 * @var bool $feesOn commitment fees are on
 */

$this->title = 'Pilots';
$termsClass = [
    Pilot::TERMS_UPCOMING => 'text-bg-info',
    Pilot::TERMS_GRACE => 'text-bg-warning',
    Pilot::TERMS_OVERDUE => 'text-bg-danger',
    Pilot::TERMS_AWAITING_APPROVAL => 'text-bg-primary',
    Pilot::TERMS_APPROVED => 'text-bg-success',
];
?>
<div class="pilot-index py-4">
    <div class="d-flex justify-content-between align-items-start">
        <h1 class="h3"><?= Html::encode($this->title) ?></h1>
        <?= Html::a('Consultants and plans', ['consultants'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
    </div>
    <p class="text-muted">
        Each facility's signed agreement and where it is with standard terms, on the clock of its earliest pilot.
        Commitment fees are <?= $feesOn ? '<strong>on</strong>' : '<strong>off</strong> (COMMITMENT_FEE_ENABLED=0)' ?>.
    </p>

    <table class="table table-sm align-middle">
        <thead><tr><th>Facility</th><th>Agreement</th><th>Consultants</th><th>Pilot day</th><th>Standard terms</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $facility = $row['facility']; $agreement = $row['agreement']; ?>
            <tr id="facility-<?= (int) $facility->id ?>">
                <td><?= Html::a(Html::encode($facility->name), ['facility', 'id' => $facility->id]) ?><?= $facility->active ? '' : ' <span class="badge text-bg-secondary">not taking bookings</span>' ?></td>
                <td>
                    <?php if ($agreement): ?>
                        v<?= Html::encode($agreement->version) ?>, signed <?= Html::encode(date('j M Y', strtotime($agreement->signed_on))) ?>
                        <?= $agreement->ended_on ? '<div class="small text-danger">Left ' . Html::encode(date('j M Y', strtotime($agreement->ended_on))) . '</div>' : '' ?>
                    <?php else: ?>
                        <span class="text-warning">None recorded</span>
                    <?php endif; ?>
                </td>
                <td><?= $row['consultants'] ?></td>
                <td><?= $row['day'] ?? '—' ?></td>
                <td><span class="badge <?= $termsClass[$row['terms']] ?? 'text-bg-light' ?>"><?= Html::encode(Pilot::termsLabels()[$row['terms']]) ?></span></td>
                <td class="text-end">
                    <?php if ($row['terms'] === Pilot::TERMS_AWAITING_APPROVAL): ?>
                        <?= Html::beginForm(['approve', 'id' => $facility->id], 'post', ['class' => 'd-inline'])
                            . Html::hiddenInput('return', \yii\helpers\Url::to(['index']))
                            . Html::submitButton('Approve standard terms', ['class' => 'btn btn-sm btn-success', 'data-confirm' => "Approve standard terms for {$facility->name}? They apply from now."])
                            . Html::endForm() ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-muted">No facilities yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
