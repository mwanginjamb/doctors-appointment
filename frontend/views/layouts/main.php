<?php

/** @var yii\web\View $this */
/** @var string $content */

use frontend\assets\CustomAsset;
use yii\helpers\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use yii\bootstrap5\Breadcrumbs;
use common\widgets\Alert;

CustomAsset::register($this);

$this->registerCsrfMetaTags();
$this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
$this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, shrink-to-fit=no']);
$this->registerMetaTag(['name' => 'description', 'content' => $this->params['meta_description'] ?? '']);
$this->registerMetaTag(['name' => 'keywords', 'content' => $this->params['meta_keywords'] ?? '']);
$this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'href' => Yii::getAlias('@web/favicon.ico')]);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">

<head>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>

<body class="d-flex flex-column h-100">
    <?php $this->beginBody() ?>

    <header class="bg-white shadow-sm">
        <?php
        NavBar::begin([
            'brandLabel' => Html::tag(
                'div',
                Html::tag(
                    'svg',
                    Html::tag('path', '', [
                        'd' => 'M13.8261 17.4264C16.7203 18.1174 20.2244 18.5217 24 18.5217C27.7756 18.5217 31.2797 18.1174 34.1739 17.4264C36.9144 16.7722 39.9967 15.2331 41.3563 14.1648L24.8486 40.6391C24.4571 41.267 23.5429 41.267 23.1514 40.6391L6.64374 14.1648C8.00331 15.2331 11.0856 16.7722 13.8261 17.4264Z',
                        'fill' => 'currentColor'
                    ]) .
                    Html::tag('path', '', [
                        'clip-rule' => 'evenodd',
                        'd' => 'M39.998 12.236C39.9944 12.2537 39.9875 12.2845 39.9748 12.3294C39.9436 12.4399 39.8949 12.5741 39.8346 12.7175C39.8168 12.7597 39.7989 12.8007 39.7813 12.8398C38.5103 13.7113 35.9788 14.9393 33.7095 15.4811C30.9875 16.131 27.6413 16.5217 24 16.5217C20.3587 16.5217 17.0125 16.131 14.2905 15.4811C12.0012 14.9346 9.44505 13.6897 8.18538 12.8168C8.17384 12.7925 8.16216 12.767 8.15052 12.7408C8.09919 12.6249 8.05721 12.5114 8.02977 12.411C8.00356 12.3152 8.00039 12.2667 8.00004 12.2612C8.00004 12.261 8 12.2607 8.00004 12.2612C8.00004 12.2359 8.0104 11.9233 8.68485 11.3686C9.34546 10.8254 10.4222 10.2469 11.9291 9.72276C14.9242 8.68098 19.1919 8 24 8C28.8081 8 33.0758 8.68098 36.0709 9.72276C37.5778 10.2469 38.6545 10.8254 39.3151 11.3686C39.9006 11.8501 39.9857 12.1489 39.998 12.236ZM4.95178 15.2312L21.4543 41.6973C22.6288 43.5809 25.3712 43.5809 26.5457 41.6973L43.0534 15.223C43.0709 15.1948 43.0878 15.1662 43.104 15.1371L41.3563 14.1648C43.104 15.1371 43.1038 15.1374 43.104 15.1371L43.1051 15.135L43.1065 15.1325L43.1101 15.1261L43.1199 15.1082C43.1276 15.094 43.1377 15.0754 43.1497 15.0527C43.1738 15.0075 43.2062 14.9455 43.244 14.8701C43.319 14.7208 43.4196 14.511 43.5217 14.2683C43.6901 13.8679 44 13.0689 44 12.2609C44 10.5573 43.003 9.22254 41.8558 8.2791C40.6947 7.32427 39.1354 6.55361 37.385 5.94477C33.8654 4.72057 29.133 4 24 4C18.867 4 14.1346 4.72057 10.615 5.94478C8.86463 6.55361 7.30529 7.32428 6.14419 8.27911C4.99695 9.22255 3.99999 10.5573 3.99999 12.2609C3.99999 13.1275 4.29264 13.9078 4.49321 14.3607C4.60375 14.6102 4.71348 14.8196 4.79687 14.9689C4.83898 15.0444 4.87547 15.1065 4.9035 15.1529C4.91754 15.1762 4.92954 15.1957 4.93916 15.2111L4.94662 15.223L4.95178 15.2312ZM35.9868 18.996L24 38.22L12.0131 18.996C12.4661 19.1391 12.9179 19.2658 13.3617 19.3718C16.4281 20.1039 20.0901 20.5217 24 20.5217C27.9099 20.5217 31.5719 20.1039 34.6383 19.3718C35.082 19.2658 35.5339 19.1391 35.9868 18.996Z',
                        'fill' => 'currentColor',
                        'fill-rule' => 'evenodd'
                    ]),
                    [
                        'class' => 'h-8 w-8',
                        'fill' => 'none',
                        'height' => '32',
                        'viewBox' => '0 0 48 48',
                        'width' => '32',
                        'xmlns' => 'http://www.w3.org/2000/svg',
                    ]
                ) .
                Html::tag('span', 'HealthFirst'),
                ['class' => 'd-flex align-items-center gap-2 fs-5 fw-bold text-dark']
            ),
            'brandUrl' => Yii::$app->homeUrl,
            'options' => [
                'class' => 'navbar-expand-lg',
            ],
            'innerContainerOptions' => [
                'class' => 'container'
            ]
        ]);

        echo Nav::widget([
            'options' => ['class' => 'navbar-nav mx-auto'],
            'items' => [
                ['label' => 'Home', 'url' => ['/site/index']],
                // ['label' => 'Services', 'url' => ['/site/services']],
                // ['label' => 'About', 'url' => ['/site/about']],
                // ['label' => 'Contact', 'url' => ['/site/contact']],
                [
                    'label' => 'My Appointments',
                    'url' => ['/appointments/index'],
                    'visible' => !Yii::$app->user->isGuest,
                ],
                [
                    'label' => 'Consultant Practice',
                    'url' => ['/consultant/index'],
                    'visible' => !Yii::$app->user->isGuest && Yii::$app->user->identity->role == 'consultant',
                ]
            ],
        ]);

        // User menu section - using native Bootstrap dropdown
        if (Yii::$app->user->isGuest) {
            echo Html::tag(
                'div',
                Html::a('Login', ['/site/login'], ['class' => 'btn btn-outline-primary me-2']) .
                Html::a('Sign Up', ['/site/signup'], ['class' => 'btn btn-primary']),
                ['class' => 'd-flex align-items-center gap-2 mt-3 mt-lg-0']
            );
        } else {
            // Bootstrap native dropdown for authenticated users
            echo Html::tag(
                'div',
                \Yii::$app->utility->currentaction('consultant', 'view') ? Html::a('Book Appointment', ['/appointments/calendar', 'cid' => Yii::$app->request->get('id')], ['class' => 'btn btn-primary me-3']) : '' .
                Html::tag(
                    'div',
                    Html::tag(
                        'button',
                        Html::img(Yii::$app->user->identity->avatar ?? 'https://placehold.co/50/cccccc/FFFFFF.webp/?text=User', [
                            'class' => 'rounded-circle me-2',
                            'width' => '32',
                            'height' => '32',
                            'alt' => 'User Avatar'
                        ]) .
                        Html::encode(Yii::$app->user->identity->username ?? 'User'),
                        [
                            'class' => 'btn btn-light dropdown-toggle d-flex align-items-center',
                            'type' => 'button',
                            'data-bs-toggle' => 'dropdown',
                            'aria-expanded' => 'false',
                            'id' => 'userDropdown'
                        ]
                    ) .
                    Html::tag(
                        'ul',
                        Html::tag('li', Html::tag('h6', 'My Account', ['class' => 'dropdown-header'])) .
                        Html::tag('li', Html::a(
                            Html::tag('i', '', ['class' => 'bi bi-person me-2']) . 'Profile',
                            ['/user/profile'],
                            ['class' => 'dropdown-item']
                        )) .
                        Html::tag('li', Html::a(
                            Html::tag('i', '', ['class' => 'bi bi-gear me-2']) . 'Settings',
                            ['/user/settings'],
                            ['class' => 'dropdown-item']
                        )) .
                        Html::tag('li', Html::tag('hr', '', ['class' => 'dropdown-divider'])) .
                        Html::tag(
                            'li',
                            Html::beginForm(['/site/logout'], 'post') .
                            Html::submitButton(
                                Html::tag('i', '', ['class' => 'bi bi-box-arrow-right me-2']) . 'Logout',
                                [
                                    'class' => 'dropdown-item text-danger',
                                    'onclick' => 'return confirm("Are you sure you want to logout?")'
                                ]
                            ) .
                            Html::endForm()
                        ),
                        [
                            'class' => 'dropdown-menu dropdown-menu-end',
                            'aria-labelledby' => 'userDropdown'
                        ]
                    ),
                    ['class' => 'dropdown']
                ),
                ['class' => 'd-flex align-items-center mt-3 mt-lg-0']
            );
        }

        NavBar::end();
        ?>
    </header>

    <main class="flex-shrink-0" role="main">
        <div class="container">
            <?php if (!empty($this->params['breadcrumbs'])): ?>
                <?= Breadcrumbs::widget([
                    'homeLink' => [
                        'label' => Yii::t('yii', 'Home'),
                        'url' => Yii::$app->homeUrl,
                    ],
                    'links' => $this->params['breadcrumbs'],
                    'options' => ['class' => 'breadcrumb my-3']
                ]) ?>
            <?php endif ?>
            <?= Alert::widget() ?>
            <?= $content ?>
        </div>
    </main>

    <footer class="footer mt-auto bg-white border-top">
        <div class="container py-3 text-center text-muted">
            <p class="mb-0">&copy; <?= date('Y') ?> HealthFirst. <?= Yii::t('app', 'All rights reserved.') ?></p>
        </div>
    </footer>

    <?php $this->endBody() ?>
</body>

</html>
<?php $this->endPage() ?>