<?php

use yii\helpers\Url;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\ClinicStats $stats */
/** @var frontend\models\Facility|null $facility the facility the numbers are for; null = whole clinic */
/** @var string $month Y-m, for the per-facility report */

$this->title = 'Settings';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="body-content py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <h1 class="h3 fw-bold mb-3">Dashboard</h1>
            <?php if (!\common\models\Consent::isEnabled()): ?>
                <!-- F11 switched off with CONSENT_ENABLED=false -->
                <div class="alert alert-warning" id="consent-off-warning">
                    <strong>Consent recording is off</strong> (<code>CONSENT_ENABLED=false</code>). Patients are not asked
                    to agree to the privacy notice and staff are not asked to confirm verbal consent. Clinics in Kenya need
                    it on to comply with the Data Protection Act, 2019. Answers recorded earlier are kept.
                </div>
            <?php endif; ?>
            <?= $this->render('_health') ?>
            <?= Html::beginForm(['settings'], 'get', ['class' => 'row g-2 align-items-end mb-3', 'id' => 'dashboard-facility']) ?>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small" for="dashboard-facility-select">Facility</label>
                    <?= Html::dropDownList('facility', $facility->id ?? null, \frontend\models\Facility::find()->select(['name', 'id'])->orderBy(['name' => SORT_ASC])->indexBy('id')->column(), [
                        'class' => 'form-select form-select-sm', 'id' => 'dashboard-facility-select', 'prompt' => 'Whole clinic',
                    ]) ?>
                </div>
                <?= Html::hiddenInput('month', $month) ?>
                <div class="col-auto"><?= Html::submitButton('Show', ['class' => 'btn btn-sm btn-primary']) ?></div>
            <?= Html::endForm() ?>
            <?= $this->render('_clinic_stats', ['stats' => $stats, 'scopeLabel' => $facility ? $facility->name : 'the whole clinic']) ?>
            <?= $this->render('_facility_report', ['stats' => $stats, 'month' => $month, 'facility' => $facility]) ?>

            <!-- content -->
            <div class="card mb-5">
                <div class="card-body text-center">
                    <h2 class="card-title h2 mb-4">Settings</h2>
                    <div class="border mx-auto"></div>
                    <div class="row">
                        <div class="col-12">
                            <div
                                class="d-flex gap-4 flex-column flex-md-row align-items-center justify-content-evenly my-5">
                                <?= Html::a('Appointments Board', Url::toRoute(['board/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Consultants', Url::toRoute(['manage-consultants/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Front Desk Staff', Url::toRoute(['front-desk/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('RBAC', Url::toRoute(['rbac/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('System Users', Url::toRoute(['site/users']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Gender', Url::toRoute(['gender/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Specializations', Url::toRoute(['specialization/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Insurance Providers', Url::toRoute(['provider/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Public Holidays', Url::toRoute(['holiday/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Audit Log', Url::toRoute(['audit/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                                <?= Html::a('Closure Requests', Url::toRoute(['closure-request/index']), ['class' => 'link-secondary text-decoration-none fw-semibold']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>