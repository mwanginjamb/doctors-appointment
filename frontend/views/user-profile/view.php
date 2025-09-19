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
                                <p class="fw-medium"><?= $model->phone ?></p>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="border-top pt-3">
                                <p class="text-muted small mb-1">Email</p>
                                <p class="fw-medium"><?= $model->email ?></p>
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
                                <p class="fw-medium"><?= $model->insurance_names ?></p>
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
                        Notifications will be sent to your email <b> <?= $model->user->email ?></b> and phone number
                        <b> <?= $model->phone ?></b>.
                    </p>

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