<?php

namespace frontend\components;

use yii\web\View;
use yii\helpers\Json;
use frontend\assets\CustomAsset;

/**
 * Builds the option lists for the main-area / sub-specialty pickers and wires up
 * web/Js/specialization-picker.js (select2 with instant client-side ranking, free entry,
 * and sub-specialty suggestions filtered by the chosen main area).
 *
 * Usage in a view:
 *   [$items, $options] = SpecializationPicker::mainOptions($catalogue, $model->speciality);
 *   echo $form->field($model, 'speciality')->dropDownList($items, ['prompt' => '', 'options' => $options]);
 *   SpecializationPicker::register($this, '#consultant-speciality', '#consultant-sub_specialities');
 */
class SpecializationPicker
{
    /**
     * @param array $catalogue from Specialization::catalogue()
     * @param string|null $current saved value, kept selectable even if it is not in the catalogue
     * @return array [items name => name, per-option HTML attributes]
     */
    public static function mainOptions(array $catalogue, $current = null)
    {
        $items = $options = [];
        foreach ($catalogue['main'] as $name => $info) {
            $items[$name] = $name;
            $options[$name] = [
                'data-main' => 1,
                'data-desc' => $info['description'],
                'data-children' => implode('|', $info['children']),
            ];
        }
        // A consultant whose saved speciality is a sub-specialty or a custom entry keeps it
        if ($current !== null && $current !== '' && !isset($items[$current])) {
            $items[$current] = $current;
            $options[$current] = isset($catalogue['sub'][$current])
                ? ['data-desc' => $catalogue['sub'][$current]['description'], 'data-children' => '']
                : ['data-children' => ''];
        }

        return [$items, $options];
    }

    /**
     * @param array $catalogue from Specialization::catalogue()
     * @param string|string[]|null $current saved value(s), kept selectable even if not in the catalogue
     * @return array [items name => name, per-option HTML attributes]
     */
    public static function subOptions(array $catalogue, $current = null)
    {
        $items = $options = [];
        foreach ($catalogue['sub'] as $name => $info) {
            $items[$name] = $name;
            $options[$name] = ['data-parent' => $info['parent'], 'data-desc' => $info['description']];
        }
        foreach ((array) $current as $value) {
            if ($value !== null && $value !== '' && !isset($items[$value])) {
                $items[$value] = $value;
            }
        }

        return [$items, $options];
    }

    /**
     * @param View $view
     * @param string $mainSelector CSS selector of the main-area <select>
     * @param string $subSelector CSS selector of the sub-specialty <select>
     * @param array $options extra JS options (e.g. placeholders)
     */
    public static function register(View $view, $mainSelector, $subSelector, array $options = [])
    {
        $view->registerJsFile('@web/Js/specialization-picker.js', ['depends' => [CustomAsset::class]]);
        $view->registerCss(<<<CSS
.select2-container--default .select2-selection--single,
.select2-container--default .select2-selection--multiple { min-height: calc(1.5em + .75rem + 2px); border: 1px solid #dee2e6; border-radius: .375rem; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: calc(1.5em + .75rem); padding-left: .75rem; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: calc(1.5em + .75rem); }
.select2-container--default.select2-container--focus .select2-selection--multiple,
.select2-container--default.select2-container--open .select2-selection--single { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
.sp-option-hint { font-size: .8em; opacity: .75; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.select2-results__option--highlighted .sp-option-hint { opacity: .9; }
.sp-option mark { padding: 0; background: rgba(255, 193, 7, .45); color: inherit; }
CSS
        , [], 'specialization-picker');

        $config = Json::htmlEncode(array_merge(['main' => $mainSelector, 'sub' => $subSelector], $options));
        $view->registerJs("SpecializationPicker.init($config);", View::POS_READY);
    }
}
