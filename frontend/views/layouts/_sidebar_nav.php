<?php

use yii\helpers\Html;
use common\components\RbacHelper;

/** @var yii\web\View $this */

$user = Yii::$app->user;
$controllerId = Yii::$app->controller->id;
$currentRoute = Yii::$app->controller->route;
// A facility admin's facility (null for everyone else)
$ownFacilityId = \frontend\components\FrontDesk::isFacilityAdmin() ? (\frontend\components\FrontDesk::facilityId() ?: null) : null;

/*
 * Menu definition.
 *  - Leaf item:   label, url, icon, optional 'match' (controller ids or routes that mark it active), optional 'visible'
 *  - Group item:  label, icon, 'group' => true, 'children' => [...leaf items], optional 'visible'
 */
$menuItems = [
    [
        'label' => 'Dashboard',
        'url' => ['/site/settings'],
        'icon' => 'dashboard',
        'match' => ['site/settings'],
        'visible' => $user->can(RbacHelper::ROLE_ADMIN),
    ],
    [
        'label' => 'Appointments Board',
        'url' => ['/board/index'],
        'icon' => 'event_note',
        'match' => ['board'],
        'visible' => \frontend\components\FrontDesk::isStaff(),
    ],
    [
        'label' => 'Consultants',
        'url' => ['/manage-consultants/index'],
        'icon' => 'stethoscope',
        'match' => ['manage-consultants'],
        'visible' => $user->can(RbacHelper::ROLE_ADMIN),
    ],
    [
        'label' => 'Front Desk Staff',
        'url' => ['/front-desk/index'],
        'icon' => 'support_agent',
        'match' => ['front-desk'],
        // Consultancy admins for every facility, a facility admin for theirs
        'visible' => $user->can(RbacHelper::ROLE_ADMIN)
            || ($ownFacilityId && $user->can(RbacHelper::PERM_MANAGE_FRONT_DESK, ['facilityId' => $ownFacilityId])),
    ],
    [
        'label' => 'Facility Admins',
        'url' => ['/facility-admin/index'],
        'icon' => 'domain',
        'match' => ['facility-admin'],
        'visible' => $user->can(RbacHelper::ROLE_ADMIN),
    ],
    [
        // A facility admin's own facility: contact details and closed days
        'label' => 'My Facility',
        'url' => ['/facility/update', 'id' => $ownFacilityId],
        'icon' => 'local_hospital',
        'match' => ['facility/update', 'facility/closures'],
        'visible' => $ownFacilityId && !$user->can(RbacHelper::PERM_MANAGE_REFERENCE_DATA),
    ],
    // ── Reference data used by consultant profiles, search and booking ──
    [
        'label' => 'Reference Data',
        'icon' => 'dataset',
        'group' => true,
        'children' => [
            ['label' => 'Facilities', 'url' => ['/facility/index'], 'icon' => 'local_hospital', 'match' => ['facility/index', 'facility/create', 'facility/update']],
            [
                'label' => 'Facility Requests' . (($n = $user->can(RbacHelper::PERM_MANAGE_REFERENCE_DATA) ? \frontend\models\FacilityRequest::pendingCount() : 0) ? " ($n)" : ''),
                'url' => ['/facility/requests'],
                'icon' => 'add_location_alt',
                'match' => ['facility/requests', 'facility/create-from-request'],
            ],
            ['label' => 'Specializations', 'url' => ['/specialization/index'], 'icon' => 'medical_services', 'match' => ['specialization']],
            ['label' => 'Genders', 'url' => ['/gender/index'], 'icon' => 'wc', 'match' => ['gender']],
            ['label' => 'Insurance Providers', 'url' => ['/provider/index'], 'icon' => 'health_and_safety', 'match' => ['provider']],
            ['label' => 'Public Holidays', 'url' => ['/holiday/index'], 'icon' => 'event_busy', 'match' => ['holiday']],
        ],
        'visible' => $user->can(RbacHelper::PERM_MANAGE_REFERENCE_DATA),
    ],
    // ── RBAC management ──
    [
        'label' => 'RBAC Mgt',
        'icon' => 'admin_panel_settings',
        'group' => true,
        'children' => [
            ['label' => 'User Role Assignment', 'url' => ['/rbac/user-roles'], 'icon' => 'manage_accounts', 'match' => ['rbac/user-roles', 'rbac/assign-role']],
            ['label' => 'Roles', 'url' => ['/rbac/index'], 'icon' => 'security_update_good', 'match' => ['rbac/index', 'rbac/create-role', 'rbac/update-role']],
            ['label' => 'Permissions', 'url' => ['/rbac/permissions'], 'icon' => 'security', 'match' => ['rbac/permissions', 'rbac/create-permission', 'rbac/update-permission']],
            ['label' => 'System Users', 'url' => ['/site/users'], 'icon' => 'group', 'match' => ['site/users'], 'visible' => $user->can(RbacHelper::PERM_MANAGE_USERS)],
        ],
        'visible' => $user->can(RbacHelper::PERM_MANAGE_RBAC),
    ],
    [
        // F11: patients who withdrew consent
        'label' => 'Closure Requests' . (($open = $user->can(RbacHelper::PERM_MANAGE_USERS) ? \common\models\AccountClosureRequest::find()->where(['status' => 'open'])->count() : 0) ? " ($open)" : ''),
        'url' => ['/closure-request/index'],
        'icon' => 'person_off',
        'match' => ['closure-request'],
        // Hidden while consent recording is off, unless requests are still open
        'visible' => $user->can(RbacHelper::PERM_MANAGE_USERS) && (\common\models\Consent::isEnabled() || $open),
    ],
    [
        'label' => 'Audit Log',
        'url' => ['/audit/index'],
        'icon' => 'history',
        'match' => ['audit'],
        'visible' => $user->can(RbacHelper::PERM_VIEW_AUDIT_LOG)
            || ($ownFacilityId && $user->can(RbacHelper::PERM_VIEW_FACILITY_AUDIT_LOG, ['facilityId' => $ownFacilityId])),
    ],
    [
        'label' => 'Back to Site',
        'url' => ['/site/index'],
        'icon' => 'home',
        'match' => [],
    ],
];

$isActive = function (array $item) use ($controllerId, $currentRoute) {
    foreach ($item['match'] ?? [] as $pattern) {
        if ($pattern === $currentRoute || $pattern === $controllerId) {
            return true;
        }
    }
    return false;
};

$renderLink = function (array $item, $extraClass = '') use ($isActive) {
    $class = trim('nav-link d-flex align-items-center gap-3 rounded-3 ' . $extraClass . ($isActive($item) ? ' active' : ''));
    return Html::a(
        '<span class="material-symbols-outlined">' . Html::encode($item['icon'] ?? 'chevron_right') . '</span><span>' . Html::encode($item['label']) . '</span>',
        $item['url'],
        ['class' => $class, 'aria-current' => $isActive($item) ? 'page' : null]
    );
};

foreach ($menuItems as $index => $item) {
    if (isset($item['visible']) && !$item['visible']) {
        continue;
    }

    if (empty($item['group'])) {
        echo $renderLink($item);
        continue;
    }

    $children = array_filter($item['children'], function ($child) {
        return !isset($child['visible']) || $child['visible'];
    });
    if (!$children) {
        continue;
    }

    $open = (bool) array_filter($children, $isActive);
    $collapseId = 'sidebar-group-' . $index;

    echo Html::button(
        '<span class="material-symbols-outlined">' . Html::encode($item['icon'] ?? 'folder') . '</span>'
        . '<span class="flex-grow-1 text-start">' . Html::encode($item['label']) . '</span>'
        . '<span class="material-symbols-outlined sidebar-chevron">expand_more</span>',
        [
            'class' => 'nav-link sidebar-group-toggle d-flex align-items-center gap-3 rounded-3 w-100 border-0 bg-transparent' . ($open ? '' : ' collapsed'),
            'data-bs-toggle' => 'collapse',
            'data-bs-target' => '#' . $collapseId,
            'aria-expanded' => $open ? 'true' : 'false',
            'aria-controls' => $collapseId,
        ]
    );

    echo Html::beginTag('div', ['class' => 'collapse' . ($open ? ' show' : ''), 'id' => $collapseId]);
    echo Html::beginTag('div', ['class' => 'nav flex-column ps-3 border-start ms-3 mb-1']);
    foreach ($children as $child) {
        echo $renderLink($child, 'py-2 small');
    }
    echo Html::endTag('div');
    echo Html::endTag('div');
}
