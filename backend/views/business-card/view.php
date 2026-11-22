<?php

use frontend\services\BusinessCard;
use yii\bootstrap5\Html;

/**
 * F29: a consultant's business card in /admin.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant $consultant
 * @var frontend\models\Facility[] $facilities on the card
 * @var frontend\models\Facility[] $allFacilities active ones they work at, by id
 */

$this->title = 'Business card: ' . $consultant->displayName();
$this->params['breadcrumbs'][] = ['label' => 'Pilots', 'url' => ['/pilot/index']];
$this->params['breadcrumbs'][] = ['label' => 'Consultants and plans', 'url' => ['/pilot/consultants']];
$this->params['breadcrumbs'][] = 'Business card';
$onCard = array_map(function ($f) { return (int) $f->id; }, $facilities);
?>
<div class="business-card-admin py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        The QR code opens <?= Html::a(Html::encode($consultant->profileUrl(true)), $consultant->profileUrl(true), ['target' => '_blank', 'id' => 'card-profile-link']) ?>,
        the public profile (no sign-in). Name, speciality, KMPDC number and practice name come from the consultant's profile.
    </p>

    <div class="bg-light rounded-3 py-4 px-2 mb-3">
        <?= $this->render('@frontend/views/business-card/_card', ['consultant' => $consultant, 'facilities' => $facilities]) ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?= Html::a('Print or save as PDF', ['print', 'id' => $consultant->id], ['class' => 'btn btn-primary', 'target' => '_blank', 'id' => 'card-print']) ?>
        <?= Html::a('QR code (SVG)', ['qr', 'id' => $consultant->id, 'format' => 'svg'], ['class' => 'btn btn-outline-secondary', 'id' => 'card-qr-svg']) ?>
        <?= Html::a('QR code (PNG)', ['qr', 'id' => $consultant->id, 'format' => 'png'], ['class' => 'btn btn-outline-secondary', 'id' => 'card-qr-png']) ?>
    </div>

    <div class="card" style="max-width: 32rem">
        <div class="card-body">
            <h2 class="h6">Facilities on the card</h2>
            <?php if ($allFacilities): ?>
                <?= Html::beginForm(['facilities', 'id' => $consultant->id], 'post', ['id' => 'card-facilities-form']) ?>
                    <p class="small text-muted mb-2">Up to <?= BusinessCard::MAX_FACILITIES ?>. The public profile lists all of them.</p>
                    <?php foreach ($allFacilities as $id => $facility): ?>
                        <div class="form-check">
                            <?= Html::checkbox('facilities[]', in_array((int) $id, $onCard, true), ['value' => $id, 'class' => 'form-check-input', 'id' => 'card-facility-' . $id]) ?>
                            <?= Html::label(Html::encode($facility->name), 'card-facility-' . $id, ['class' => 'form-check-label']) ?>
                        </div>
                    <?php endforeach; ?>
                    <?= Html::submitButton('Save', ['class' => 'btn btn-sm btn-outline-primary mt-2']) ?>
                <?= Html::endForm() ?>
            <?php else: ?>
                <p class="small text-muted mb-0">The consultant works at no facility taking bookings yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
