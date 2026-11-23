<?php

use yii\bootstrap5\Html;

/**
 * F30: an average out of 5 with how many of each star. Shared by My ratings and /admin.
 *
 * @var yii\web\View $this
 * @var array $summary Ratings::summary()
 */

if (!$summary || !$summary['count']) {
    echo '<p class="text-muted" id="ratings-none">' . Yii::t('app', 'No ratings yet.') . '</p>';

    return;
}
?>
<div class="d-flex flex-wrap align-items-center gap-4 mb-3" id="ratings-summary">
    <div>
        <div class="display-6 fw-bold" id="ratings-average"><?= number_format($summary['average'], 1) ?><span class="fs-5 text-muted"> / 5</span></div>
        <div class="text-warning fs-5" aria-hidden="true"><?= str_repeat('★', (int) round($summary['average'])) . str_repeat('☆', 5 - (int) round($summary['average'])) ?></div>
        <div class="small text-muted"><?= Html::encode(Yii::t('app', '{n, plural, one{# rating} other{# ratings}}', ['n' => $summary['count']])) ?></div>
    </div>
    <div class="flex-grow-1" style="max-width: 18rem">
        <?php foreach ($summary['stars'] as $n => $count): ?>
            <div class="d-flex align-items-center gap-2 small">
                <span style="width: 2.5rem"><?= $n ?> ★</span>
                <div class="progress flex-grow-1" style="height: .5rem">
                    <div class="progress-bar bg-warning" style="width: <?= $summary['count'] ? round(100 * $count / $summary['count']) : 0 ?>%"></div>
                </div>
                <span class="text-muted" style="width: 2rem"><?= $count ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
