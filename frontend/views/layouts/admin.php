<?php

/** @var yii\web\View $this */
/** @var string $content */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use yii\bootstrap5\Breadcrumbs;
use app\assets\AppAsset;
use frontend\assets\AdminAsset;

AdminAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">

<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?> - HealthAdmin</title>
    <?php $this->head() ?>
</head>

<body class="d-flex flex-column h-100 bg-light">
    <?php $this->beginBody() ?>

    <div class="d-flex flex-fill">
        <!-- Sidebar -->
        <aside class="sidebar bg-white p-3 shadow-sm d-flex flex-column">
            <!-- Logo and Brand -->
            <div class="d-flex align-items-center gap-2 px-2 py-3">
                <?= Html::tag('svg', Html::tag('path', '', [
                    'd' => 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z',
                    'stroke-linecap' => 'round',
                    'stroke-linejoin' => 'round'
                ]), [
                    'class' => 'text-primary',
                    'fill' => 'none',
                    'height' => '32',
                    'stroke' => 'currentColor',
                    'stroke-width' => '1.5',
                    'viewBox' => '0 0 24 24',
                    'width' => '32',
                    'xmlns' => 'http://www.w3.org/2000/svg'
                ]) ?>
                <h1 class="h5 fw-bold mb-0 text-dark">HealthAdmin</h1>
            </div>

            <!-- Navigation Menu -->
            <nav class="nav nav-pills flex-column mt-4">
                <?= $this->render('_sidebar_nav') ?>
            </nav>

            <!-- Logout Button -->
            <div class="mt-auto">
                <?= Html::a(
                    '<span class="material-symbols-outlined">logout</span><span>Logout</span>',
                    ['/site/logout'],
                    [
                        'class' => 'nav-link d-flex align-items-center gap-3 rounded-3',
                        'data-method' => 'post'
                    ]
                ) ?>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-grow-1 p-4 p-md-5">
            <div class="container-fluid">
                <!-- Breadcrumbs -->
                <?= Breadcrumbs::widget([
                    'links' => $this->params['breadcrumbs'] ?? [],
                    'options' => ['class' => 'breadcrumb mb-4'],
                ]) ?>

                <!-- Flash Messages -->
                <?php foreach (Yii::$app->session->getAllFlashes() as $type => $messages): ?>
                    <?php foreach ((array) $messages as $message): ?>
                        <div class="alert alert-<?= $type === 'error' ? 'danger' : $type ?> alert-dismissible fade show"
                            role="alert">
                            <?= Html::encode($message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endforeach ?>
                <?php endforeach ?>

                <!-- Page Content -->
                <?= $content ?>
            </div>
        </main>
    </div>

    <?php $this->endBody() ?>
</body>

</html>
<?php $this->endPage() ?>