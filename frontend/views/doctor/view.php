<?php

use frontend\services\BusinessCard;
use yii\bootstrap5\Html;

/**
 * F29: a consultant's public profile (no sign-in), opened from their business card's QR code.
 * No personal phone or email: patients reach them through their facilities.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant $consultant
 * @var frontend\models\Facility[] $facilities active ones they work at
 * @var bool $taking their account is active
 */

$name = $consultant->displayName();
$speciality = BusinessCard::specialityLine($consultant);
$this->title = $name . ($speciality ? ' · ' . $speciality : '');
$this->registerMetaTag(['name' => 'description', 'content' => trim($name . ', ' . $speciality . '. '
    . ($facilities ? 'Sees patients at ' . implode(', ', array_map(function ($f) { return $f->name; }, $facilities)) . '. ' : '')
    . 'Book an appointment online.')]);
$this->registerMetaTag(['property' => 'og:title', 'content' => $this->title]);
$this->registerMetaTag(['property' => 'og:url', 'content' => $consultant->profileUrl(true)]);
$this->registerLinkTag(['rel' => 'canonical', 'href' => $consultant->profileUrl(true)]);
?>
<div class="doctor-profile container my-4" style="max-width: 44rem" id="doctor-profile">
    <div class="text-center mb-4">
        <h1 class="h2 fw-bold mb-1"><?= Html::encode($name) ?></h1>
        <?php if ($speciality): ?>
            <p class="lead text-muted mb-1" id="doctor-speciality"><?= Html::encode($speciality) ?></p>
        <?php endif; ?>
        <?php if ($consultant->practice_name): ?>
            <p class="mb-1"><?= Html::encode($consultant->practice_name) ?></p>
        <?php endif; ?>
        <?php if ($consultant->kmpdc_registration_number): ?>
            <p class="small text-muted mb-0"><?= Html::encode(Yii::t('app', 'KMPDC Reg. No. {number}', ['number' => $consultant->kmpdc_registration_number])) ?></p>
        <?php endif; ?>
        <?php if ($rated = \frontend\services\Ratings::publicSummary($consultant)): ?>
            <!-- F30: patients' ratings, once there are enough -->
            <p class="mt-2 mb-0" id="doctor-rating">
                <span class="text-warning" aria-hidden="true"><?= str_repeat('★', (int) round($rated['average'])) . str_repeat('☆', 5 - (int) round($rated['average'])) ?></span>
                <strong><?= number_format($rated['average'], 1) ?></strong>
                <span class="text-muted small"><?= Html::encode(Yii::t('app', 'from {n} patient ratings', ['n' => $rated['count']])) ?></span>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($taking): ?>
        <div class="text-center mb-4">
            <?= Html::a(Yii::t('app', 'Book an appointment'), ['/appointments/calendar', 'cid' => $consultant->id], ['class' => 'btn btn-primary btn-lg', 'id' => 'doctor-book']) ?>
            <?php if (Yii::$app->user->isGuest): ?>
                <div class="small text-muted mt-2"><?= Yii::t('app', 'You sign in or register (free) to choose a time.') ?></div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary text-center" id="doctor-not-taking">
            <?= Html::encode(Yii::t('app', '{doctor} is not taking appointments through this site.', ['doctor' => $name])) ?>
        </div>
    <?php endif; ?>

    <?php if ($facilities): ?>
        <h2 class="h5 mb-3"><?= Yii::t('app', 'Where to find {doctor}', ['doctor' => $name]) ?></h2>
        <div id="doctor-facilities">
            <?php foreach ($facilities as $facility): ?>
                <div class="card border-0 shadow-sm mb-2">
                    <div class="card-body py-2">
                        <div class="fw-semibold"><?= Html::encode($facility->name) ?></div>
                        <?php if ($facility->address): ?>
                            <div class="small text-muted"><?= Html::encode(preg_replace('/\s*\R\s*/', ', ', $facility->address)) ?></div>
                        <?php endif; ?>
                        <div class="small">
                            <?php if ($facility->phone): ?>
                                <?= Html::a(Html::encode($facility->phone), 'tel:' . preg_replace('/[^\d+]/', '', $facility->phone)) ?>
                            <?php endif; ?>
                            <?php if ($facility->map_url): ?>
                                <?= $facility->phone ? ' · ' : '' ?><?= Html::a(Yii::t('app', 'Map'), $facility->map_url, ['target' => '_blank', 'rel' => 'noopener']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="text-center mt-4">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="doctor-share"
            data-url="<?= Html::encode($consultant->profileUrl(true)) ?>" data-title="<?= Html::encode($name) ?>">
            <?= Yii::t('app', 'Share this page') ?>
        </button>
    </div>
</div>
<?php
$this->registerJs(<<<'JS'
(function () {
    var button = document.getElementById('doctor-share');
    if (!button) { return; }
    button.addEventListener('click', function () {
        var url = button.dataset.url;
        if (navigator.share) {
            navigator.share({title: button.dataset.title, url: url}).catch(function () {});
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () { button.textContent = 'Link copied'; });
        } else {
            window.prompt('Copy this link', url);
        }
    });
})();
JS);
