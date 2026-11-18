<?php

use frontend\services\FeeRules;
use frontend\services\Pilot;
use yii\bootstrap5\Html;
use yii\helpers\Url;

/**
 * F28: consultants with their pilot and plan, each with a form to change the plan and a button to
 * restart the pilot. Used on the facility page and the consultants page.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant[] $consultants
 * @var bool $showFacilities list each consultant's facilities
 */

$return = Url::current();
?>
<table class="table table-sm align-middle">
    <thead>
        <tr>
            <th>Consultant</th>
            <?php if ($showFacilities): ?><th>Facilities</th><?php endif; ?>
            <th>First booking</th><th>Day</th><th>Phase</th><th style="min-width: 24rem">Plan</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($consultants as $consultant): ?>
        <?php $day = Pilot::day($consultant); ?>
        <tr id="consultant-<?= (int) $consultant->id ?>">
            <td>
                <?= Html::encode($consultant->names) ?>
                <?= $consultant->user && $consultant->user->email ? '<div class="small text-muted">' . Html::encode($consultant->user->email) . '</div>' : '' ?>
            </td>
            <?php if ($showFacilities): ?>
                <td class="small"><?= Html::encode(implode(', ', array_map(function ($e) { return $e->facility->name ?? ''; }, $consultant->engagements))) ?: '—' ?></td>
            <?php endif; ?>
            <td><?= $consultant->first_booking_at ? Html::encode(date('j M Y', $consultant->first_booking_at)) : '—' ?></td>
            <td><?= $day ?? '—' ?></td>
            <td class="small"><?= Html::encode(Pilot::phaseLabels()[Pilot::phase($consultant)]) ?></td>
            <td>
                <?= Html::beginForm(['plan', 'id' => $consultant->id], 'post', ['class' => 'd-flex flex-wrap align-items-center gap-2'])
                    . Html::hiddenInput('return', $return)
                    . Html::dropDownList('plan', $consultant->plan, FeeRules::planLabels(), ['class' => 'form-select form-select-sm w-auto', 'aria-label' => 'Plan'])
                    . '<div class="form-check mb-0">'
                    . Html::checkbox('insured_pay', (bool) $consultant->insured_pay, ['class' => 'form-check-input', 'id' => 'insured-pay-' . $consultant->id, 'value' => 1])
                    . Html::label('Plan A: insured patients pay', 'insured-pay-' . $consultant->id, ['class' => 'form-check-label small'])
                    . '</div>'
                    . Html::submitButton('Save', ['class' => 'btn btn-sm btn-outline-primary'])
                    . Html::endForm() ?>
            </td>
            <td class="text-end">
                <?php if ($consultant->first_booking_at): ?>
                    <?= Html::beginForm(['restart', 'id' => $consultant->id], 'post', ['class' => 'd-inline'])
                        . Html::hiddenInput('return', $return)
                        . Html::submitButton('Restart pilot', [
                            'class' => 'btn btn-sm btn-link text-danger',
                            'data-confirm' => "Clear {$consultant->names}'s pilot start? It starts again at their next booking. Only for test bookings made before the pilot.",
                        ])
                        . Html::endForm() ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$consultants): ?>
        <tr><td colspan="<?= $showFacilities ? 7 : 6 ?>" class="text-muted">No consultants.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
