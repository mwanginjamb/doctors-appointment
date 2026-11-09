<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * A consultant can hold several sub-specialties.
 *
 * Each row keeps the name as entered, and links to the specialization catalogue when the name
 * matches an entry (specialization_id is NULL for names a consultant typed that are not in it).
 * Existing comma-separated `consultant.sub_speciality` text is migrated into rows.
 */
class m261109_110100_create_consultant_sub_speciality_table extends Migration
{
    const TABLE = '{{%consultant_sub_speciality}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull(),
            'specialization_id' => $this->integer()->null(),
            'name' => $this->string(250)->notNull(),
            'created_at' => $this->integer(),
        ]);
        $this->createIndex('idx-consultant_sub_speciality-unique', self::TABLE, ['consultant_id', 'name'], true);
        $this->createIndex('idx-consultant_sub_speciality-specialization_id', self::TABLE, 'specialization_id');
        $this->createIndex('idx-consultant_sub_speciality-name', self::TABLE, 'name');
        $this->addForeignKey('fk-consultant_sub_speciality-consultant_id', self::TABLE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-consultant_sub_speciality-specialization_id', self::TABLE, 'specialization_id', '{{%specialization}}', 'id', 'SET NULL', 'CASCADE');

        $catalogue = [];
        foreach ((new Query())->select(['id', 'specialization'])->from('{{%specialization}}')->all($this->db) as $row) {
            $catalogue[mb_strtolower(trim((string) $row['specialization']))] = (int) $row['id'];
        }

        $rows = [];
        $now = time();
        $consultants = (new Query())->select(['id', 'sub_speciality'])->from('{{%consultant}}')
            ->where(['not', ['sub_speciality' => null]])->andWhere(['<>', 'sub_speciality', ''])->all($this->db);
        foreach ($consultants as $consultant) {
            $seen = [];
            foreach (preg_split('/\s*[,;\n]\s*/', trim($consultant['sub_speciality'])) as $name) {
                $name = mb_substr(trim($name), 0, 250);
                $key = mb_strtolower($name);
                if ($name === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [(int) $consultant['id'], $catalogue[$key] ?? null, $name, $now];
            }
        }
        if ($rows) {
            $this->batchInsert(self::TABLE, ['consultant_id', 'specialization_id', 'name', 'created_at'], $rows);
        }
        echo '    > migrated ' . count($rows) . " existing sub-speciality value(s)\n";
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
