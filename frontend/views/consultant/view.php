<?php

use Symfony\Component\VarDumper\VarDumper;
use yii\helpers\Html;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var app\models\Consultant $model */

$this->title = $model->names;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Consultants'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
?>
<div class="consultant-view">

    <!-- <h1><?= Html::encode($this->title) ?></h1> -->



    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-4">
                        <?= Html::img(Yii::$app->user->avatar ?? 'https://placehold.co/150/cccccc/FFFFFF.webp/?text=' . rawurlencode(explode(' ', trim((string) $model->names))[1] ?? (string) $model->names), [
                            'class' => 'rounded-circle me-2',
                            'width' => '128',
                            'height' => '128',
                            'alt' => 'User Avatar'
                        ]) ?>
                        <div>
                            <div class="d-flex justify-content-between">
                                <h1 class="h3 fw-bold"><?= Html::encode($model->names) ?></h1>
                                <!-- Add an edit link with an icon -->
                                <div class="edit">
                                    <?= Yii::$app->user->can(\common\components\RbacHelper::PERM_UPDATE_CONSULTANT_PROFILE, ['consultant' => $model]) ? Html::a('<i class="bi bi-pencil-square"></i>', ['update', 'id' => $model->id], ['class' => 'btn btn-link', 'title' => 'Update Your Consultant\'s Profile. ']) : '' ?>
                                </div>
                            </div>
                            <p class="text-muted mb-1"><?= Html::encode($model->speciality) ?></p>
                            <?php if ($model->sub_speciality): ?>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php foreach (preg_split('/\s*,\s*/', $model->sub_speciality) as $sub): ?>
                                        <span class="badge rounded-pill text-bg-light border fw-normal"><?= Html::encode($sub) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-muted"><?= Html::encode($model->experience) ?> years of experience</p>
                        </div>
                    </div>
                    <h2 class="h5 fw-bold mt-5 mb-3">Contact Information</h2>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Phone</p>
                                <p class="fw-medium"><?= Html::encode($model->consultant_phone_number) ?></p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Email</p>
                                <p class="fw-medium"><?= Html::encode($model->consultant_email) ?></p>
                            </div>
                        </div>
                    </div>
                    <h2 class="h5 fw-bold mt-4 mb-3">Practice Details</h2>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Facilities</p>
                                <?php foreach ($model->getHoursByFacility() as $row): ?>
                                    <?php $engagement = $row['engagement']; ?>
                                    <div class="mb-2">
                                        <?= $this->render('//facility/_place', ['facility' => $engagement->facility, 'fallback' => null]) ?>
                                        <div class="small text-muted">
                                            <?= Html::encode($row['summary'] ?? 'No hours yet') ?>
                                            · <?= (int) $engagement->session_minutes ?> min<?= $engagement->getFeeLabel() ? ' · ' . Html::encode($engagement->getFeeLabel()) : '' ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Address</p>
                                <p class="fw-medium"><?= Html::encode($model->physical_address) ?></p>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="border-top pt-3">
                                        <p class="text-muted small mb-1">Working Hours</p>
                                        <p class="fw-medium"><?= Html::encode($model->getHoursSummary() ?? 'Not taking bookings') ?>
                                            <?php if ((int) $model->user_id === (int) Yii::$app->user->id): ?>
                                                <?= Html::a('Edit', ['availability/index'], ['class' => 'small ms-2']) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="border-top pt-3">
                                        <p class="text-muted small mb-1">Practice Name</p>
                                        <p class="fw-medium"><?= Html::encode($model->practice_name) ?></p>
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
                    <h2 class="h5 fw-bold mb-3">Book an Appointment</h2>
                    <p class="text-muted small mb-4">Select a date and time to book your consultation with
                        <b> <?= Html::encode($model->names) ?></b>.
                    </p>
                    <!-- <a class="btn btn-primary w-100 btn-lg" href="#">Book Appointment</a> -->
                    <?= \yii\bootstrap5\Html::a('Book Appointment', ['appointments/calendar', 'cid' => $model->id], ['class' => 'btn btn-primary w-100 btn-lg']) ?>
                    <hr class="my-4" />
                    <h3 class="h6 fw-bold mb-3">Accepted Medical Covers</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?= $this->render('_covers', ['covers' => $model->covers_supported_names]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>