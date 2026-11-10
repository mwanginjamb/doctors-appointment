<?php

namespace frontend\models;

use common\models\AuditLog;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Filters for the admin audit log page.
 */
class AuditLogSearch extends Model
{
    public $from;
    public $to;
    public $actor;
    public $actor_type;
    public $action;
    public $entity_type;
    public $entity_id;

    public function rules()
    {
        return [
            [['from', 'to'], 'date', 'format' => 'php:Y-m-d'],
            [['actor', 'entity_id'], 'string', 'max' => 150],
            [['actor_type'], 'in', 'range' => array_keys(AuditLog::actorTypes())],
            [['action'], 'in', 'range' => array_keys(AuditLog::actions())],
            [['entity_type'], 'in', 'range' => array_keys(AuditLog::entityTypes())],
        ];
    }

    public function formName()
    {
        return ''; // short query strings: ?action=login&from=...
    }

    public function attributeLabels()
    {
        return [
            'actor' => 'Who (name)',
            'actor_type' => 'As',
            'entity_type' => 'Record',
            'entity_id' => 'Record #',
        ];
    }

    public function search(array $params)
    {
        $query = AuditLog::find();
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id']],
            'pagination' => ['pageSize' => 50],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->where('0=1');
            return $dataProvider;
        }

        $query->andFilterWhere([
            'actor_type' => $this->actor_type,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id !== null && $this->entity_id !== '' ? trim($this->entity_id) : null,
        ]);
        $query->andFilterWhere(['like', 'actor_name', $this->actor]);
        if ($this->from) {
            $query->andWhere(['>=', 'created_at', strtotime($this->from . ' 00:00:00')]);
        }
        if ($this->to) {
            $query->andWhere(['<', 'created_at', strtotime($this->to . ' +1 day')]);
        }

        return $dataProvider;
    }
}
