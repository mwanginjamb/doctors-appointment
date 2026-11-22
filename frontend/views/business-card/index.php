<?php

use frontend\services\BusinessCard;
use yii\bootstrap5\Html;

/**
 * F29: a consultant's business card, both sides at real size, with print, QR download and share.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant $consultant
 * @var frontend\models\Facility[] $facilities on the card
 * @var frontend\models\Facility[] $allFacilities active ones they work at, by id
 * @var bool $canEdit the consultant: chooses the facilities
 * @var frontend\models\Consultant[] $others a front desk's consultants, to switch between
 */

$this->title = Yii::t('app', 'Business card');
$profileUrl = $consultant->profileUrl(true);
$onCard = array_map(function ($f) { return (int) $f->id; }, $facilities);
?>
<div class="business-card-page container my-4" style="max-width: 60rem">
    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
        <h1 class="h3 fw-bold mb-0"><?= Html::encode($this->title) ?><?= $canEdit ? '' : ': ' . Html::encode($consultant->displayName()) ?></h1>
        <?php if ($others): ?>
            <div class="small">
                <?= Yii::t('app', 'Card for') ?>
                <?php foreach ($others as $other): ?>
                    <?= (int) $other->id === (int) $consultant->id
                        ? '<strong class="ms-1">' . Html::encode($other->displayName()) . '</strong>'
                        : Html::a(Html::encode($other->displayName()), ['index', 'cid' => $other->id], ['class' => 'ms-1']) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <p class="text-muted">
        <?= Yii::t('app', 'Side A says who you are and where you work; the QR code on side B opens your public profile, where patients see your facilities and book.', []) ?>
        <?= Html::a(Yii::t('app', 'Open the profile'), $consultant->profileUrl(), ['target' => '_blank', 'id' => 'card-profile-link']) ?>
    </p>

    <div class="bg-light rounded-3 py-4 px-2 mb-3" style="zoom: 1.25">
        <?= $this->render('_card', ['consultant' => $consultant, 'facilities' => $facilities]) ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4" id="card-actions">
        <?= Html::a('<i class="bi bi-printer me-1"></i>' . Yii::t('app', 'Print or save as PDF'), ['print', 'cid' => $consultant->id], ['class' => 'btn btn-primary', 'target' => '_blank', 'id' => 'card-print']) ?>
        <?= Html::a('<i class="bi bi-qr-code me-1"></i>' . Yii::t('app', 'QR code (SVG, for print)'), ['qr', 'cid' => $consultant->id, 'format' => 'svg'], ['class' => 'btn btn-outline-secondary', 'id' => 'card-qr-svg']) ?>
        <?= Html::a('<i class="bi bi-image me-1"></i>' . Yii::t('app', 'QR code (PNG)'), ['qr', 'cid' => $consultant->id, 'format' => 'png'], ['class' => 'btn btn-outline-secondary', 'id' => 'card-qr-png']) ?>
        <button type="button" class="btn btn-outline-secondary" id="card-share" data-url="<?= Html::encode($profileUrl) ?>"
            data-title="<?= Html::encode($consultant->displayName()) ?>"><i class="bi bi-share me-1"></i><?= Yii::t('app', 'Share the profile link') ?></button>
    </div>

    <?php if ($canEdit): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6"><?= Yii::t('app', 'Facilities on the card') ?></h2>
                <?php if ($allFacilities): ?>
                    <?= Html::beginForm(['index'], 'post', ['id' => 'card-facilities-form']) ?>
                        <p class="small text-muted mb-2"><?= Yii::t('app', 'Up to {n}, with their phone and address. Your profile lists all of them.', ['n' => BusinessCard::MAX_FACILITIES]) ?></p>
                        <?php foreach ($allFacilities as $id => $facility): ?>
                            <div class="form-check">
                                <?= Html::checkbox('facilities[]', in_array((int) $id, $onCard, true), ['value' => $id, 'class' => 'form-check-input card-facility', 'id' => 'card-facility-' . $id]) ?>
                                <?= Html::label(Html::encode($facility->name), 'card-facility-' . $id, ['class' => 'form-check-label']) ?>
                            </div>
                        <?php endforeach; ?>
                        <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-sm btn-outline-primary mt-2']) ?>
                    <?= Html::endForm() ?>
                <?php else: ?>
                    <p class="small text-muted mb-0"><?= Yii::t('app', 'Add the facilities you work at on Availability to show them here.') ?></p>
                <?php endif; ?>
                <p class="small text-muted mt-3 mb-0">
                    <?= Yii::t('app', 'Your name, speciality, KMPDC number and practice name come from your profile.') ?>
                    <?= Html::a(Yii::t('app', 'Edit your profile'), ['/consultant/update', 'id' => $consultant->id]) ?>
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$max = BusinessCard::MAX_FACILITIES;
$this->registerJs(<<<JS
(function () {
    var share = document.getElementById('card-share');
    share.addEventListener('click', function () {
        var url = share.dataset.url;
        if (navigator.share) {
            navigator.share({title: share.dataset.title, url: url}).catch(function () {});
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () { share.lastChild.textContent = 'Link copied'; });
        } else {
            window.prompt('Copy this link', url);
        }
    });
    // At most $max facilities
    var boxes = document.querySelectorAll('.card-facility');
    function limit() {
        var n = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
        boxes.forEach(function (b) { b.disabled = !b.checked && n >= $max; });
    }
    boxes.forEach(function (b) { b.addEventListener('change', limit); });
    limit();
})();
JS);
