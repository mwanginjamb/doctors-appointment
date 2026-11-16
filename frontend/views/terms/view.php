<?php

use frontend\services\Pilot;
use yii\bootstrap5\Html;

/**
 * @var yii\web\View $this
 * @var frontend\models\Facility $facility
 * @var frontend\models\FacilityAgreement|null $agreement
 * @var string $status Pilot::TERMS_*
 * @var string|null $start Y-m-d, standard terms start (day 91)
 * @var string|null $graceEnds Y-m-d, last day of the grace period
 * @var bool $canConfirm
 * @var bool $canApprove
 */

$this->title = Yii::t('app', 'Standard terms: {facility}', ['facility' => $facility->name]);
$this->params['breadcrumbs'][] = Yii::t('app', 'Standard terms');
$f = Yii::$app->formatter;
?>
<div class="terms-view my-3" style="max-width: 820px">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <?php if ($agreement === null): ?>
        <div class="alert alert-secondary">
            <?= Yii::t('app', 'No pilot agreement is recorded for this facility yet. Please contact the platform team.') ?>
        </div>
    <?php else: ?>
        <p class="text-muted">
            <?= Html::encode(Yii::t('app', 'Pilot agreement v{version}, signed on {date} by {signatory}.', [
                'version' => $agreement->version,
                'date' => $f->asDate($agreement->signed_on, 'long'),
                'signatory' => $agreement->facility_signatory,
            ])) ?>
        </p>

        <?php if ($status === Pilot::TERMS_APPROVED): ?>
            <div class="alert alert-success" id="terms-approved">
                <?= Html::encode(Yii::t('app', 'Standard terms were confirmed by the facility on {confirmed} and approved by the platform on {approved}.', [
                    'confirmed' => $f->asDate($agreement->standard_terms_confirmed_at, 'long'),
                    'approved' => $f->asDate($agreement->standard_terms_approved_at, 'long'),
                ])) ?>
            </div>
        <?php elseif ($status === Pilot::TERMS_AWAITING_APPROVAL): ?>
            <div class="alert alert-info" id="terms-confirmed">
                <?= Html::encode(Yii::t('app', 'The facility confirmed standard terms on {date}. They apply once the platform approves them.', ['date' => $f->asDate($agreement->standard_terms_confirmed_at, 'long')])) ?>
            </div>
        <?php elseif ($status === Pilot::TERMS_NOT_DUE): ?>
            <div class="alert alert-info">
                <?= $start
                    ? Html::encode(Yii::t('app', 'The pilot is running. Standard terms start on {date}; you can confirm them now or nearer the time.', ['date' => $f->asDate($start, 'long')]))
                    : Yii::t('app', 'The pilot starts with the first booking at this facility. Standard terms follow 90 days later.') ?>
            </div>
        <?php elseif ($status === Pilot::TERMS_UPCOMING): ?>
            <div class="alert alert-info">
                <?= Html::encode(Yii::t('app', 'Standard terms start on {date}. Please confirm them below.', ['date' => $f->asDate($start, 'long')])) ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <?= Html::encode($status === Pilot::TERMS_GRACE
                    ? Yii::t('app', 'Standard terms started on {start}. Please confirm them by {end}. Until then, consultants here are not charged.', ['start' => $f->asDate($start, 'long'), 'end' => $f->asDate($graceEnds, 'long')])
                    : Yii::t('app', 'The time to confirm standard terms ended on {end}. Please confirm them, or contact the platform team.', ['end' => $f->asDate($graceEnds, 'long')])) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card border-0 shadow-sm my-4">
        <div class="card-body">
            <h2 class="h5"><?= Yii::t('app', 'Standard terms (from day 91)') ?></h2>
            <p><?= Yii::t('app', 'Each consultant who had an appointment in the month is charged for that month on the plan they choose:') ?></p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th><?= Yii::t('app', 'Plan') ?></th><th><?= Yii::t('app', 'Consultant') ?></th><th><?= Yii::t('app', 'Patient') ?></th><th><?= Yii::t('app', 'Front desk') ?></th></tr></thead>
                    <tbody>
                        <tr>
                            <td><strong><?= Yii::t('app', 'Plan A: commitment fee') ?></strong></td>
                            <td><?= Yii::t('app', 'Ksh 1,000 a month') ?></td>
                            <td><?= Yii::t('app', 'Ksh 250 per advance booking, by M-Pesa') ?></td>
                            <td><?= Yii::t('app', 'Ksh 50 per completed booking, paid weekly') ?></td>
                        </tr>
                        <tr>
                            <td><strong><?= Yii::t('app', 'Plan B: flat') ?></strong></td>
                            <td><?= Yii::t('app', 'Ksh 1,500 a month') ?></td>
                            <td><?= Yii::t('app', 'None') ?></td>
                            <td><?= Yii::t('app', 'None') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php if ($agreement !== null && $agreement->hasCancellationCharge()): ?>
                <p class="mb-0"><?= Yii::t('app', 'On either plan, Ksh 100 is charged for each appointment the consultant or their front desk cancels, except when the patient asked or the facility was closed.') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($agreement !== null && $status === Pilot::TERMS_AWAITING_APPROVAL): ?>
        <?php if ($canApprove): ?>
            <?= Html::beginForm(['/terms/approve', 'facility' => $facility->id], 'post', ['id' => 'approve-terms-form']) ?>
            <button type="submit" class="btn btn-success"><?= Yii::t('app', 'Approve standard terms') ?></button>
            <?= Html::endForm() ?>
        <?php else: ?>
            <p class="text-muted"><?= Yii::t('app', 'Waiting for the platform to approve.') ?></p>
        <?php endif; ?>
    <?php elseif ($agreement !== null && $status !== Pilot::TERMS_APPROVED): ?>
        <?php if ($canConfirm): ?>
            <?= Html::beginForm(['/terms/confirm', 'facility' => $facility->id], 'post', ['id' => 'confirm-terms-form']) ?>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="terms-agree" required>
                <label class="form-check-label" for="terms-agree">
                    <?= Html::encode(Yii::t('app', 'I confirm, for {facility}, the move to these standard terms.', ['facility' => $facility->name])) ?>
                </label>
            </div>
            <button type="submit" class="btn btn-primary"><?= Yii::t('app', 'Confirm standard terms') ?></button>
            <?= Html::endForm() ?>
        <?php else: ?>
            <p class="text-muted"><?= Yii::t('app', "The facility's front desk or facility admin confirms these terms.") ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
