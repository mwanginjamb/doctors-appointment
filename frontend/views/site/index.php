<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */

$this->title = 'Find a Doctor';
?>
<div class="site-index">

    <div class="body-content">
        <div class="row">
            <div class="col-lg-12">
                <div class="header text-center pb-5">
                    <h1 class="display-4">Find a Doctor</h1>
                </div>
                <div class="search">
                    <?php $form = ActiveForm::begin(); ?>
                    <div class="row">
                        <div class="col-md-6 py-3">

                            <?= $form->field($model, 'speciality')->textInput(['placeholder' => 'Enter a speciality']) ?>
                        </div>
                        <div class="col-md-6 py-3">
                            <?= $form->field($model, 'physical_address')->textInput(['placeholder' => 'County, Town, Building']) ?>
                        </div>

                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">Search</button>
                        </div>
                    </div>
                    <?php ActiveForm::end(); ?>
                </div>

                <!-- results -->
                <div class="row my-3">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                Search Results
                            </h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Speciality</th>
                                        <th>Physical Address</th>
                                        <td>Action</td>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $res): ?>
                                        <tr>
                                            <td><?= $res->names ?></td>
                                            <td><?= $res->speciality ?></td>
                                            <td><?= $res->physical_address ?></td>
                                            <td><?= Html::a('Book Appointment', Url::toRoute(['appointments/calendar', 'cid' => $res->id])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Results -->
            </div>
        </div>

    </div>
</div>