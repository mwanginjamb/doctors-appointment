<?php

namespace frontend\components;

use frontend\assets\CustomAsset;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * A calendar of free slots to choose one from (Js/slot-picker.js). Choosing a slot fills the date
 * and time inputs, shows the choice and enables the submit button. Used by the booking-link page
 * and the patient's self-service page.
 *
 * Only one per page: the script looks for #slot-picker.
 */
class SlotPicker extends Widget
{
    /** @var array Y-m-d => ['H:i', ...] free start times */
    public $slots = [];

    /** @var int length of a session, in minutes */
    public $minutes = 30;

    /** @var string label on each free slot */
    public $eventTitle = 'Free';

    /** @var string|null first day shown (Y-m-d); defaults to the first day with slots */
    public $from;

    /** @var string|null last day shown (Y-m-d); defaults to the last day with slots */
    public $to;

    /** @var bool page a week at a time instead of showing the whole range */
    public $weekly = false;

    /** @var array ids of the page elements a choice fills: dateInput, timeInput, label, button, scrollTo (optional) */
    public $targets = [];

    /** @var string shown before the chosen slot, e.g. "Your time: " */
    public $labelPrefix = '';

    /** @var array HTML attributes of the calendar container */
    public $options = [];

    public function run()
    {
        $view = $this->getView();
        $view->registerJsFile('@web/Js/slot-picker.js', ['depends' => [CustomAsset::class]]);
        // Grey out the grid behind the free slots so they stand out
        $view->registerCss('#slot-picker .fc-timegrid-col { background: #f1f3f5; }');

        return Html::tag('div', '', ['id' => 'slot-picker'] + $this->options)
            . Html::tag('script', json_encode($this->clientConfig(), JSON_HEX_TAG | JSON_HEX_AMP), [
                'type' => 'application/json',
                'id' => 'slot-picker-config',
            ]);
    }

    /**
     * Settings for Js/slot-picker.js.
     */
    public function clientConfig()
    {
        $dates = array_keys($this->slots);
        $from = $this->from ?? ($dates[0] ?? date('Y-m-d'));
        $to = $this->to ?? (end($dates) ?: $from);

        return array_merge([
            'events' => $this->events($windowStart, $windowEnd),
            'from' => $from,
            'end' => date('Y-m-d', strtotime($to . ' +1 day')),
            'days' => count($this->slots),
            'weekly' => (bool) $this->weekly,
            'slotDuration' => sprintf('%02d:%02d:00', intdiv($this->minutes, 60), $this->minutes % 60),
            'slotMinTime' => $windowStart ?? '08:00',
            'slotMaxTime' => $windowEnd ?? '17:00',
            'labelPrefix' => $this->labelPrefix,
            'scrollTo' => null,
        ], $this->targets);
    }

    /**
     * The free slots as FullCalendar events, and the earliest start and latest end among them.
     */
    private function events(&$windowStart, &$windowEnd)
    {
        $events = [];
        $windowStart = $windowEnd = null;
        foreach ($this->slots as $date => $times) {
            foreach ($times as $time) {
                $start = strtotime("$date $time");
                $end = $start + $this->minutes * 60;
                $events[] = [
                    'start' => date('Y-m-d\TH:i:s', $start),
                    'end' => date('Y-m-d\TH:i:s', $end),
                    'title' => $this->eventTitle,
                    'extendedProps' => ['date' => $date, 'time' => $time, 'label' => date('l j F \a\t H:i', $start)],
                ];
                $windowStart = $windowStart === null ? $time : min($windowStart, $time);
                $windowEnd = $windowEnd === null ? date('H:i', $end) : max($windowEnd, date('H:i', $end));
            }
        }
        return $events;
    }
}
