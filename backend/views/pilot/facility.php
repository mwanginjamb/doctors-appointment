<?php

use frontend\models\FacilityAgreement;
use frontend\services\Pilot;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * F28: one facility's agreements, standard terms and consultants.
 *
 * @var yii\web\View $this
 * @var frontend\models\Facility $facility
 * @var FacilityAgreement[] $agreements latest first
 * @var FacilityAgreement|null $current
 * @var FacilityAgreement $form a new agreement to record
 * @var frontend\models\Consultant[] $consultants
 * @var int|null $day the facility's pilot day
 * @var string $terms Pilot::TERMS_*
 */

$this->title = $facility->name;
$this->params['breadcrumbs'][] = ['label' => 'Pilots', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
$who = function ($userId) {
    $user = $userId ? \common\models\User::findOne($userId) : null;
    return $user ? ($user->full_name ?: $user->username) : 'the console';
};
?>
<div class="pilot-facility py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted mb-4">
        Pilot day <?= $day ?? '— (no booking yet)' ?>
        <?php if ($day): ?>
            · standard terms from <?= Html::encode(date('j M Y', strtotime(Pilot::standardTermsStart($facility->id)))) ?>
            · grace ends <?= Html::encode(date('j M Y', strtotime(Pilot::graceEnds($facility->id)))) ?>
        <?php endif; ?>
    </p>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">Standard terms</h2>
            <p id="terms-status" class="mb-2"><strong><?= Html::encode(Pilot::termsLabels()[$terms]) ?></strong></p>
            <?php if ($current && $current->standard_terms_confirmed_at): ?>
                <p class="small text-muted mb-2">
                    Confirmed <?= $f->asDatetime($current->standard_terms_confirmed_at, 'php:j M Y H:i') ?> by <?= Html::encode($who($current->standard_terms_confirmed_by)) ?>
                    <?php if ($current->standard_terms_approved_at): ?>
                        · approved <?= $f->asDatetime($current->standard_terms_approved_at, 'php:j M Y H:i') ?> by <?= Html::encode($who($current->standard_terms_approved_by)) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($current && !$current->standard_terms_confirmed_at && !$current->ended_on): ?>
                <?= Html::beginForm(['confirm', 'id' => $facility->id], 'post', ['class' => 'd-inline'])
                    . Html::submitButton('Confirm for the facility', [
                        'class' => 'btn btn-sm btn-outline-primary',
                        'data-confirm' => "Confirm standard terms on {$facility->name}'s behalf? Only when it confirmed by phone or in writing; its front desk or facility admin normally confirm in the app.",
                    ])
                    . Html::endForm() ?>
            <?php endif; ?>
            <?php if ($terms === Pilot::TERMS_AWAITING_APPROVAL): ?>
                <?= Html::beginForm(['approve', 'id' => $facility->id], 'post', ['class' => 'd-inline'])
                    . Html::submitButton('Approve standard terms', ['class' => 'btn btn-sm btn-success', 'data-confirm' => "Approve standard terms for {$facility->name}? They apply from now."])
                    . Html::endForm() ?>
            <?php endif; ?>
            <?php if (!$current): ?>
                <p class="small text-warning mb-0">Record the facility's signed agreement first.</p>
            <?php endif; ?>
        </div>
    </div>

    <h2 class="h5">Agreements</h2>
    <table class="table table-sm align-middle mb-3" id="agreements">
        <thead><tr><th>Version</th><th>Signed</th><th>For the facility</th><th>For the platform</th><th>Walk-ins</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($agreements as $i => $agreement): ?>
            <tr class="<?= $i === 0 ? '' : 'text-muted' ?>">
                <td>v<?= Html::encode($agreement->version) ?> <?= $i === 0 ? '<span class="badge text-bg-primary">current</span>' : '' ?></td>
                <td>
                    <?= Html::encode(date('j M Y', strtotime($agreement->signed_on))) ?>
                    <?= $agreement->ended_on ? '<div class="small text-danger">Left ' . Html::encode(date('j M Y', strtotime($agreement->ended_on))) . '</div>' : '' ?>
                </td>
                <td><?= Html::encode($agreement->facility_signatory) ?></td>
                <td><?= Html::encode($agreement->platform_signatory) ?></td>
                <td><?= $agreement->walk_in_exempt ? 'Same-day walk-ins pay no fee' : 'Walk-ins pay' ?></td>
                <td class="small"><?= Html::encode((string) $agreement->notes) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$agreements): ?>
            <tr><td colspan="6" class="text-muted">None recorded.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h3 class="h6">Record a signed agreement</h3>
                    <?php if ($current && $current->standard_terms_confirmed_at): ?>
                        <div class="alert alert-warning small py-2">
                            The current agreement's standard terms are <?= $current->standard_terms_approved_at ? 'approved' : 'confirmed' ?>.
                            A new agreement replaces it, and the facility confirms standard terms again for the new one
                            (and you approve them again). Notices already sent are not sent again.
                        </div>
                    <?php endif; ?>
                    <?php $af = ActiveForm::begin(['action' => ['agreement', 'id' => $facility->id], 'id' => 'agreement-form']); ?>
                    <div class="row g-2">
                        <div class="col-md-6"><?= $af->field($form, 'version')->dropDownList(FacilityAgreement::versionLabels()) ?></div>
                        <div class="col-md-6"><?= $af->field($form, 'signed_on')->input('date') ?></div>
                        <div class="col-md-6"><?= $af->field($form, 'facility_signatory')->textInput(['placeholder' => 'Name, title']) ?></div>
                        <div class="col-md-6"><?= $af->field($form, 'platform_signatory')->textInput(['placeholder' => 'Name, title']) ?></div>
                    </div>
                    <?= $af->field($form, 'walk_in_exempt')->checkbox() ?>
                    <?= $af->field($form, 'notes')->textarea(['rows' => 2]) ?>
                    <?= Html::submitButton('Record agreement', ['class' => 'btn btn-primary btn-sm']) ?>
                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>
        <?php if ($current && !$current->ended_on): ?>
            <div class="col-lg-5">
                <div class="card border-danger-subtle">
                    <div class="card-body">
                        <h3 class="h6">The facility left</h3>
                        <p class="small text-muted">Ends the current agreement; no more standard-terms notices go out for it.</p>
                        <?= Html::beginForm(['ended', 'id' => $facility->id], 'post', ['class' => 'd-flex gap-2'])
                            . Html::input('date', 'ended_on', date('Y-m-d'), ['class' => 'form-control form-control-sm w-auto', 'aria-label' => 'Left on'])
                            . Html::submitButton('Record', ['class' => 'btn btn-sm btn-outline-danger', 'data-confirm' => "Record that {$facility->name} left?"])
                            . Html::endForm() ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between align-items-baseline">
        <h2 class="h5">Consultants here</h2>
        <?= Html::a('Invite or add consultants ↗', \common\helpers\AdminLinks::mainSite('facility-consultants/index?facility=' . (int) $facility->id), ['class' => 'small']) ?>
    </div>
    <?= $this->render('_consultant_rows', ['consultants' => $consultants, 'showFacilities' => false]) ?>
</div>
