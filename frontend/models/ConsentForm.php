<?php

namespace frontend\models;

use common\models\Consent;
use Yii;
use yii\base\Model;

/**
 * The two consent questions (F11), asked wherever a patient joins: consent to data processing
 * (required) and to WhatsApp/SMS reminders (a yes or no, nothing pre-chosen).
 *
 * Used on its own on the consent page; sign-up, walk-in registration and booking links ask the same
 * two questions through their own forms and call save() the same way.
 */
class ConsentForm extends Model
{
    /** @var bool|string agrees to the privacy notice and to the processing of their data */
    public $data;

    /** @var string|null '1' yes, '0' no */
    public $reminders;

    /** @var bool staff recording what the patient said (verbal consent) */
    public $verbal = false;

    public function rules()
    {
        return [
            [['data'], 'required', 'requiredValue' => '1', 'message' => $this->dataMessage()],
            [['reminders'], 'required', 'message' => Yii::t('app', 'Choose whether you want WhatsApp/SMS reminders.')],
            [['reminders'], 'in', 'range' => ['0', '1']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'data' => $this->verbal
                ? Yii::t('app', 'The patient agreed to the privacy notice and to the clinic using their information, including health details, for their appointments')
                : Yii::t('app', 'I have read the privacy notice and agree to the clinic using my information, including health details, to book and run my appointments'),
            'reminders' => $this->verbal
                ? Yii::t('app', 'Reminders by WhatsApp/SMS')
                : Yii::t('app', 'Send me appointment reminders and notices by WhatsApp/SMS'),
        ];
    }

    private function dataMessage()
    {
        return $this->verbal
            ? Yii::t('app', "Confirm the patient's consent first.")
            : Yii::t('app', 'Please agree to the privacy notice to continue.');
    }

    /**
     * Records the answers for a patient.
     *
     * @param string $method Consent::METHOD_*
     */
    public function save($userId, $method)
    {
        Consent::recordIfChanged($userId, Consent::PURPOSE_DATA, true, $method);
        Consent::recordIfChanged($userId, Consent::PURPOSE_REMINDERS, $this->reminders === '1' || $this->reminders === 1 || $this->reminders === true, $method);
    }

    /** Yes / no choices for the reminders question. */
    public static function reminderChoices()
    {
        return ['1' => Yii::t('app', 'Yes'), '0' => Yii::t('app', 'No')];
    }
}
