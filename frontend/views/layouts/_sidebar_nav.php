<?php

use yii\helpers\Html;
use yii\helpers\Url;

$currentRoute = Yii::$app->controller->route;

$menuItems = [
    [
        'label' => 'Specializations',
        'url' => ['/specialization/create'],
        'icon' => 'medical_services',
        'active' => in_array($currentRoute, ['specialization/index', 'specialization/create', 'specialization/update', 'specialization/view'])
    ],
    [
        'label' => 'Genders',
        'url' => ['/gender/index'],
        'icon' => 'male',
        'active' => in_array($currentRoute, ['gender/index', 'gender/create', 'admin/locations/update', 'admin/locations/view'])
    ],
    [
        'label' => 'Consultant Profiles',
        'url' => ['#'],
        'icon' => 'group',
        'active' => in_array($currentRoute, ['admin/consultants/index', 'admin/consultants/create', 'admin/consultants/update', 'admin/consultants/view'])
    ],
    [
        'label' => 'System Configurations',
        'url' => ['#'],
        'icon' => 'settings',
        'active' => in_array($currentRoute, ['admin/configurations/index', 'admin/configurations/update'])
    ],
    [
        'label' => 'Reports',
        'url' => ['#'],
        'icon' => 'analytics',
        'active' => in_array($currentRoute, ['admin/reports/index'])
    ],
];

foreach ($menuItems as $item):
    $linkClass = 'nav-link d-flex align-items-center gap-3 rounded-3';
    if ($item['active']) {
        $linkClass .= ' active';
    }
    ?>
    <?= Html::a(
        '<span class="material-symbols-outlined">' . $item['icon'] . '</span><span>' . $item['label'] . '</span>',
        $item['url'],
        ['class' => $linkClass]
    ) ?>
<?php endforeach; ?>