<?php

use yii\helpers\Html;
use yii\helpers\VarDumper;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var app\models\Appointments $model */

$this->title = $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Appointments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
// VarDumper::dump($model->consultant->genderIdentity->name, 10, true);

?>
<div class="appointments-view my-2">



    <p>
        <?php Html::a(Yii::t('app', 'Update'), ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a(Yii::t('app', 'Cancel'), ['delete', 'id' => $model->id], [
            'class' => 'btn btn-danger',
            'data' => [
                'confirm' => Yii::t('app', 'Are you sure you want to delete this item?'),
                'method' => 'post',
            ],
        ]) ?>
    </p>


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
                            <input class="form-control" disabled value="<?= $model->patient->full_name ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="last-name">Symptoms Brief</label>
                            <input class="form-control" disabled value="<?= $model->symptoms_brief ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email Address</label>
                            <input class="form-control" disabled value="<?= $model->patient->email ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input class="form-control" disabled type="tel"
                                value="<?= $model->patient->phone_number ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="dob">Date of Birth</label>
                            <input class="form-control" disabled value="<?= $model->patientProfile->dob ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="dob">Patient Age</label>
                            <input class="form-control" disabled value="<?= $model->patient_age ?? '' ?>" />
                        </div>

                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white p-4 border-bottom">
                    <h2 class="h5 fw-semibold text-dark mb-0">Consultant Details</h2>
                </div>
                <div class="card-body p-4">
                    <form class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="current-password">Names</label>
                            <input class="form-control" disabled value="<?= $model->consultant->names ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new-password">Specialization</label>
                            <input class="form-control" disabled value="<?= $model->consultant->speciality ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new-password">Physical Address</label>
                            <input class="form-control" disabled
                                value="<?= $model->consultant->physical_address ?? '' ?>" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="confirm-password">Practice Name</label>
                            <input class="form-control" disabled
                                value="<?= $model->consultant->practice_name ?? '' ?>" />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="gender">Gender</label>
                            <input class="form-control" disabled
                                value="<?= $model->consultant->genderIdentity->name ?? '' ?>" />
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


</div>