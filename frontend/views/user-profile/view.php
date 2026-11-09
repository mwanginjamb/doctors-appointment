<?php

use Symfony\Component\VarDumper\VarDumper;
use yii\helpers\Html;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var frontend\models\Consultant $model */

$this->title = 'Profile for :' . $model->user->full_name;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Consultants'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
$this->registerCss(<<<CSS
.reminder-plan summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: .35rem; }
.reminder-plan summary::-webkit-details-marker { display: none; }
.reminder-plan summary::before { content: '\\203A'; display: inline-block; transition: transform .15s ease-in-out; }
.reminder-plan[open] summary::before { transform: rotate(90deg); }
.reminder-plan summary:hover { color: var(--bs-primary) !important; }
CSS);
?>
<div class="consultant-view">

    <!-- <h1><?= Html::encode($this->title) ?></h1> -->



    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 mb-4">
                <div class="card-body">

                    <h2 class="h5 fw-bold mt-5 mb-3">Contact Information</h2>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Phone</p>
                                <p class="fw-medium"><?= Html::encode($model->phone) ?></p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Email</p>
                                <p class="fw-medium"><?= Html::encode($model->email) ?></p>
                            </div>
                        </div>
                    </div>
                    <h2 class="h5 fw-bold mt-4 mb-3">Payment Details</h2>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Cash</p>
                                <p class="fw-medium"><?= $model->cash ? 'Yes' : 'No' ?></p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Applicable Covers</p>
                                <p class="fw-medium"><?= Html::encode($model->insurance_names) ?></p>
                            </div>
                        </div>
                        <div class="col-md-12">

                            <h2 class="h5 fw-bold mt-4 mb-3">Bio Data</h2>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="border-top pt-3">
                                        <p class="text-muted small mb-1">Date of Birth</p>
                                        <p class="fw-medium"><?= Yii::$app->formatter->asDate($model->dob) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="border-top pt-3">
                                        <p class="text-muted small mb-1">Gender</p>
                                        <p class="fw-medium"><?= $model->gender == 1 ? 'Male' : 'Female' ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-3">Appointment Notification Schedules</h2>
                    <p class="text-muted small mb-4">
                        Notifications will be sent to your email <b> <?= Html::encode($model->user->email) ?></b> and phone number
                        <b> <?= Html::encode($model->phone) ?></b>.
                    </p>

                    <?php if ((int) $model->user_id === (int) Yii::$app->user->id): ?>
                        <?php $plan = \frontend\models\NotificationSchedules::describeForPatient($model->user_id); ?>
                        <details class="reminder-plan small">
                            <summary class="text-muted">When will I be reminded?</summary>
                            <div class="pt-2">
                                <?php if ($plan['items']): ?>
                                    <ul class="list-unstyled mb-2">
                                        <?php foreach ($plan['items'] as $item): ?>
                                            <li class="d-flex justify-content-between gap-3 py-1 border-bottom">
                                                <span class="fw-medium text-nowrap"><?= Html::encode($item['when']) ?></span>
                                                <span class="text-muted text-end"><?= Html::encode(implode(', ', $item['channels'])) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <p class="text-muted mb-0">
                                        <?= $plan['isDefault']
                                            ? 'These are the default reminders; they are set up when you book your first appointment.'
                                            : 'Times are counted back from the start of each appointment.' ?>
                                        Your consultant may add reminders of their own.
                                    </p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No automatic reminders are currently scheduled for you.</p>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>

                    <hr class="my-4" />
                    <h3 class="h6 fw-bold mb-3">Accepted Medical Covers</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?= $this->render('_covers', ['covers' => $model->insurance_names]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>