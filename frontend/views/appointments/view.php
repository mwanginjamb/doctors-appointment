<?php

use frontend\models\Appointments;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\helpers\VarDumper;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var frontend\models\Appointments $model */
/** @var common\models\AuditLog[]|null $history entries about this appointment, for the clinic side; null for the patient */

$this->title = $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Appointments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
// VarDumper::dump($model->consultant->genderIdentity->name, 10, true);

?>
<div class="appointments-view my-2">



    <p>
        <?php Html::a(Yii::t('app', 'Update'), ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?php if ($model->canBeCancelled()): ?>
            <?= Html::button(Yii::t('app', 'Cancel appointment'), [
                'class' => 'btn btn-danger',
                'data' => ['bs-toggle' => 'modal', 'bs-target' => '#cancelAppointmentModal'],
            ]) ?>
        <?php endif; ?>
    </p>
    <?= $model->canBeCancelled() ? $this->render('_cancel_modal', ['action' => Url::to(['cancel', 'id' => $model->id])]) : '' ?>

    <?php $staffView = \frontend\components\FrontDesk::canRecordOutcome($model); ?>
    <?php if ($staffView && $model->canRecordOutcome()): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="fw-semibold"><?= Yii::t('app', 'Did the patient come?') ?></span>
            <?= $this->render('_outcome_buttons', ['model' => $model, 'size' => '']) ?>
        </div>
    <?php endif; ?>

    <?php if (in_array($model->status, [Appointments::STATUS_COMPLETED, Appointments::STATUS_NO_SHOW], true)): ?>
        <div class="alert <?= $model->status === Appointments::STATUS_NO_SHOW ? 'alert-warning' : 'alert-success' ?>" role="status">
            <strong><?= $model->status === Appointments::STATUS_NO_SHOW ? Yii::t('app', 'No-show') : Yii::t('app', 'Attended') ?></strong>
            <?php if ($model->outcomeRecorder): ?>
                <?= Yii::t('app', 'recorded by {name}', ['name' => Html::encode($model->outcomeRecorder->full_name ?: $model->outcomeRecorder->username)]) ?>
            <?php endif; ?>
            <?php if ($model->outcome_at): ?>
                <?= Yii::t('app', 'on {date}', ['date' => Yii::$app->formatter->asDatetime($model->outcome_at)]) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($staffView && ($noShows = Appointments::countNoShows($model->patient_id, 365, \frontend\components\FrontDesk::historyCondition())) > 0): ?>
        <div class="alert alert-light border small" role="note">
            <?= Yii::t('app', 'This patient has {count} in the last 12 months.', ['count' => $noShows === 1 ? '1 no-show' : "$noShows no-shows"]) ?>
        </div>
    <?php endif; ?>

    <?php if ($model->status === Appointments::STATUS_CANCELLED): ?>
        <div class="alert alert-secondary" role="status">
            <strong><?= Yii::t('app', 'Cancelled') ?></strong>
            <?php if ($model->updater): ?>
                <?= Yii::t('app', 'by {name}', ['name' => Html::encode($model->updater->full_name ?: $model->updater->username)]) ?>
            <?php endif; ?>
            <?= Yii::t('app', 'on {date}', ['date' => Yii::$app->formatter->asDatetime($model->updated_at)]) ?>.
            <?php if ($model->cancellation_note): ?>
                <div class="mt-2"><?= Yii::t('app', 'Reason') ?>: <?= nl2br(Html::encode($model->cancellation_note)) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <header class="mb-5">
        <h1 class="h2 fw-bold text-dark">Appointment <?= '#' . $model->id ?> Details</h1>
        <p class="text-muted">Also view, consultant brief and your profile.</p>
    </header>
    <div class="row g-5">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-5">
                <div class="card-header bg-white p-4 border-bottom">
                    <h2 class="h5 fw-semibold text-dark mb-0">Patient Information</h2>
                </div>
                <div class="card-body p-4">
                    <form class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="first-name">Names</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->patient->full_name ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="last-name">Symptoms Brief</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->symptoms_brief ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email Address</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->patient->email ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input class="form-control" disabled type="tel"
                                value="<?= Html::encode($model->patient->phone_number ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="dob">Date of Birth</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->patientProfile->dob ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="dob">Patient Age</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->patient_age ?? '') ?>" />
                        </div>

                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white p-4 border-bottom">
                    <h2 class="h5 fw-semibold text-dark mb-0">Consultant Details</h2>
                </div>
                <div class="card-body p-4">
                    <?php if ($model->facility): ?>
                        <!-- F8: where this appointment takes place, and the fee recorded when it was booked -->
                        <div class="mb-3 appointment-facility">
                            <div class="form-label mb-0"><?= Yii::t('app', 'Facility') ?></div>
                            <?= $this->render('//facility/_place', ['facility' => $model->facility, 'fallback' => null]) ?>
                            <div class="small text-muted mt-1">
                                <?= (int) $model->getDurationMinutes() ?> minutes<?= $model->getFeeLabel() ? ' · ' . Html::encode($model->getFeeLabel()) : '' ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="current-password">Names</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->consultant->names ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new-password">Specialization</label>
                            <input class="form-control" disabled value="<?= Html::encode($model->consultant->speciality ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new-password">Physical Address</label>
                            <input class="form-control" disabled
                                value="<?= Html::encode($model->consultant->physical_address ?? '') ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="confirm-password">Practice Name</label>
                            <input class="form-control" disabled
                                value="<?= Html::encode($model->consultant->practice_name ?? '') ?>" />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="gender">Gender</label>
                            <input class="form-control" disabled
                                value="<?= Html::encode($model->consultant->genderIdentity->name ?? '') ?>" />
                        </div>

                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-5">
                <div class="card-header bg-white p-4 border-bottom">
                    <h2 class="h5 fw-semibold text-dark mb-0">Appointment Preferences</h2>
                </div>
                <div class="card-body p-4">
                    <form>
                        <div class="mb-3">
                            <label class="form-label" for="preferred-time">Preferred Time Slot</label>
                            <input class="form-control" disabled
                                value="<?= Yii::$app->formatter->asTime($model->time) ?>" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="preferred-consultant">Preferred Date</label>
                            <input class="form-control" disabled
                                value="<?= Yii::$app->formatter->asDate($model->date) ?>" />
                        </div>

                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white p-4 border-bottom">
                    <h2 class="h5 fw-semibold text-dark mb-0">Accepted Covers</h2>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap gap-2">
                        <?= $this->render('_covers', ['covers' => $model->consultant->covers_supported_names]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <?php if ($history !== null): ?>
        <!-- Audit log for this appointment: clinic side only (the consultant, front desk, admins) -->
        <div class="card border-0 shadow-sm my-5" id="appointment-history">
            <div class="card-header bg-white p-4 border-bottom">
                <h2 class="h5 fw-semibold text-dark mb-0">History</h2>
            </div>
            <div class="card-body p-4">
                <?php if (!$history): ?>
                    <p class="text-muted mb-0">No changes recorded yet.</p>
                <?php endif; ?>
                <?php foreach ($history as $entry): ?>
                    <div class="border-bottom py-2">
                        <div>
                            <span class="fw-semibold"><?= Html::encode($entry->getActionLabel()) ?></span>
                            <span class="text-muted small">
                                <?= date('Y-m-d H:i', $entry->created_at) ?> ·
                                <?= Html::encode($entry->actor_name ?? $entry->getActorTypeLabel()) ?>
                                (<?= Html::encode($entry->getActorTypeLabel()) ?>)
                            </span>
                        </div>
                        <?= $this->render('/audit/_changes', ['entry' => $entry]) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>