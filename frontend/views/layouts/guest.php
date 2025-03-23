<?php

/** @var \yii\web\View $this */
/** @var string $content */

use common\widgets\Alert;
use frontend\assets\AppAsset;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

AppAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">

<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>

<body class="d-flex flex-column h-100">
    <?php $this->beginBody() ?>

    <section class="bg-light p-3 p-md-4 p-xl-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-xxl-11">
                    <div class="card border-light-subtle shadow-sm">
                        <div class="row g-0">
                            <div class="col-12 col-md-6">
                                <img class="img-fluid rounded-start w-100 object-fit-cover" style="max-height: 100%;"
                                    loading="lazy"
                                    src="<?= Yii::$app->utility->webroot() ?>/images/doctors_calander_booking_logo.svg"
                                    alt="Welcome back you've been missed!">
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-center justify-content-center">
                                <div class="col-12 col-lg-11 col-xl-10">
                                    <!--card content -->

                                    <div class="card-body p-3 p-md-4 p-xl-5">
                                        <!-- row 1 -->
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="mb-5">
                                                    <div class="text-center mb-4">
                                                        <a href="#!">
                                                            <img src="<?= Yii::$app->utility->webroot() ?>/images/doctors_calander_booking_logo.svg"
                                                                alt="Specialist calendar Logo" width="175" height="57">
                                                        </a>
                                                    </div>
                                                    <h4 class="text-center"><?= $this->title ?></h4>
                                                </div>
                                            </div>
                                        </div>
                                        <!--/ row 1 -->

                                        <!-- row 2 -->
                                        <?php if (Yii::$app->utility->currentaction('site', 'login')): ?>
                                            <div class="row">
                                                <div class="col-12">
                                                    <div
                                                        class="d-flex gap-3 flex-column justify-content-center align-items-center">
                                                        <!-- <a href="#!" class="btn btn-lg btn-outline-dark">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                                fill="currentColor" class="bi bi-google"
                                                                viewBox="0 0 16 16">
                                                                <path
                                                                    d="M15.545 6.558a9.42 9.42 0 0 1 .139 1.626c0 2.434-.87 4.492-2.384 5.885h.002C11.978 15.292 10.158 16 8 16A8 8 0 1 1 8 0a7.689 7.689 0 0 1 5.352 2.082l-2.284 2.284A4.347 4.347 0 0 0 8 3.166c-2.087 0-3.86 1.408-4.492 3.304a4.792 4.792 0 0 0 0 3.063h.003c.635 1.893 2.405 3.301 4.492 3.301 1.078 0 2.004-.276 2.722-.764h-.003a3.702 3.702 0 0 0 1.599-2.431H8v-3.08h7.545z" />
                                                            </svg>
                                                            <span class="ms-2 fs-6">Log in with Google</span>
                                                        </a> -->
                                                        <?= yii\authclient\widgets\AuthChoice::widget([
                                                            'baseAuthUrl' => ['site/auth'],
                                                            'popupMode' => false,
                                                        ]) ?>
                                                    </div>
                                                    <p class="text-center mt-4 mb-5">Or sign in with</p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?= $content ?>
                                        <!-- / row 2 -->
                                        <!-- row 3 -->

                                        <!-- / row 3 -->
                                    </div>
                                    <!-- / card content -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer mt-auto py-3 text-muted">
        <div class="container">
            <p class="float-start">&copy; <?= Html::encode(Yii::$app->name) ?> <?= date('Y') ?></p>
            <p class="float-end"><?= Yii::powered() ?></p>
        </div>
    </footer>

    <?php $this->endBody() ?>
</body>

</html>
<?php $this->endPage();
