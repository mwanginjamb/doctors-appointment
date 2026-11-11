<?php

use yii\bootstrap5\Html;

/**
 * Where an appointment takes place: facility name, address, phone and map link. Shown to patients
 * and staff wherever an appointment or a booking is described.
 *
 * @var yii\web\View $this
 * @var frontend\models\Facility|null $facility
 * @var string|null $fallback shown when there is no facility (old appointments)
 */

if ($facility === null) {
    echo !empty($fallback) ? Html::tag('div', Html::encode($fallback), ['class' => 'text-muted small']) : '';
    return;
}
?>
<div class="facility-place small mt-1">
    <span class="d-inline-block rounded-circle me-1 align-middle" style="width:.65rem;height:.65rem;background:<?= Html::encode($facility->getColor()) ?>"></span>
    <strong><?= Html::encode($facility->name) ?></strong>
    <?php if ($facility->address): ?>
        <span class="text-muted">· <?= Html::encode(preg_replace('/\s*\R\s*/', ', ', $facility->address)) ?></span>
    <?php endif; ?>
    <?php if ($facility->phone): ?>
        <span class="text-muted">· <?= Html::encode($facility->phone) ?></span>
    <?php endif; ?>
    <?php if ($facility->map_url): ?>
        · <?= Html::a('Map', $facility->map_url, ['target' => '_blank', 'rel' => 'noopener noreferrer']) ?>
    <?php endif; ?>
</div>
