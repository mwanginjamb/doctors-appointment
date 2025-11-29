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
                        <?= Html::img(Yii::$app->user->avatar ?? 'https://placehold.co/150/cccccc/FFFFFF.webp/?text=' . explode(' ', $model->names)[0], [
                            'class' => 'rounded-circle me-2',
                            'width' => '128',
                            'height' => '128',
                            'alt' => 'User Avatar'
                        ]) ?>
                        <div>
                            <div class="d-flex justify-content-between">
                                <h1 class="h3 fw-bold"><?= $model->names ?></h1>
                                <!-- Add an edit link with an icon -->
                                <div class="edit">
                                    <?= Yii::$app->user->identity->role === 'consultant' && Yii::$app->user->identity->id === $model->created_by ? Html::a('<i class="bi bi-pencil-square"></i>', ['update', 'id' => $model->id], ['class' => 'btn btn-link', 'title' => 'Update Your Consultant\'s Profile. ']) : '' ?>
                                </div>
                            </div>
                            <p class="text-muted mb-1"><?= $model->speciality ?></p>
                            <p class="text-muted"><?= $model->experience ?> years of experience</p>
                        </div>
                    </div>
                    <h2 class="h5 fw-bold mt-5 mb-3">Contact Information</h2>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Phone</p>
                                <p class="fw-medium"><?= $model->consultant_phone_number ?></p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Email</p>
                                <p class="fw-medium"><?= $model->consultant_email ?></p>
                            </div>
                        </div>
                    </div>
                    <h2 class="h5 fw-bold mt-4 mb-3">Practice Details</h2>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Facility Name</p>
                                <p class="fw-medium"><?= $model->facility ?></p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Address</p>
                                <p class="fw-medium"><?= $model->physical_address ?></p>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="border-top pt-3">
                                        <p class="text-muted small mb-1">Working Hours</p>
                                        <p class="fw-medium">Mon-Fri: 9 AM - 5 PM</p>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="border-top pt-3">
                                        <p class="text-muted small mb-1">Practice Name</p>
                                        <p class="fw-medium"><?= $model->practice_name ?></p>
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
                        <b> <?= $model->names ?></b>.
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