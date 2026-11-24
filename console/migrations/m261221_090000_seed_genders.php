<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Seeds the `{{%gender}}` lookup (patients' profiles and consultants).
 *
 * "Intersex" follows the Kenya 2019 census and the Children Act 2022; "Prefer not to say" lets
 * people leave it out. Names that already exist (case-insensitive) are skipped, so admin-entered
 * rows are kept. Seeded rows have created_by = NULL; safeDown() removes only those that nobody's
 * profile uses.
 */
class m261221_090000_seed_genders extends Migration
{
    public function safeUp()
    {
        $existing = array_map('mb_strtolower', (new Query())->select('name')->from('{{%gender}}')->column($this->db));

        $now = time();
        $rows = [];
        foreach (self::genders() as $name) {
            if (!in_array(mb_strtolower($name), $existing, true)) {
                $rows[] = [$name, $now, $now, null, null];
            }
        }
        if ($rows) {
            $this->batchInsert('{{%gender}}', ['name', 'created_at', 'updated_at', 'created_by', 'updated_by'], $rows);
        }
        echo '    > seeded ' . count($rows) . ' gender(s), skipped ' . (count(self::genders()) - count($rows)) . " existing\n";
    }

    public function safeDown()
    {
        $inUse = array_merge(
            (new Query())->select('gender')->distinct()->from('{{%user_profile}}')->where(['not', ['gender' => null]])->column($this->db),
            (new Query())->select('gender')->distinct()->from('{{%consultant}}')->where(['not', ['gender' => null]])->column($this->db)
        );
        $this->delete('{{%gender}}', ['and',
            ['name' => self::genders(), 'created_by' => null],
            ['not in', 'id', $inUse ?: [0]],
        ]);
    }

    /** @return string[] */
    private static function genders()
    {
        return ['Female', 'Male', 'Intersex', 'Prefer not to say'];
    }
}
