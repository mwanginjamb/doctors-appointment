<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Makes specializations two-level: main areas (parent_id NULL) and sub-specialties under them.
 *
 * Adds any missing main areas (e.g. Dentistry, Surgery) and files the seeded sub-specialties
 * under their main area. Rows that do not exist (renamed or deleted by an admin) are skipped.
 */
class m261109_110000_add_parent_id_to_specialization_table extends Migration
{
    const TABLE = '{{%specialization}}';

    /** Main areas created by this migration when missing: name => description */
    const NEW_MAIN_AREAS = [
        'Dentistry' => 'Oral health care of the teeth, gums and mouth, from routine check-ups to specialised dental treatment.',
        'Surgery' => 'Operative treatment of injuries, diseases and deformities across the body\'s organs and systems.',
        'Oncology' => 'Diagnosis and treatment of cancer, including chemotherapy, radiotherapy and immunotherapy.',
        'Psychology' => 'Assessment and talking therapies for emotional, behavioural and cognitive difficulties.',
    ];

    /** Main area => its sub-specialties */
    const HIERARCHY = [
        'Dentistry' => ['General Dentistry', 'Orthodontics', 'Periodontics', 'Endodontics', 'Prosthodontics', 'Paediatric Dentistry'],
        'Surgery' => [
            'General Surgery', 'Cardiothoracic Surgery', 'Vascular Surgery', 'Paediatric Surgery', 'Colorectal Surgery',
            'Hepatobiliary Surgery', 'Bariatric Surgery', 'Breast Surgery', 'Endocrine Surgery', 'Surgical Oncology',
            'Trauma Surgery', 'Transplant Surgery', 'Plastic and Reconstructive Surgery', 'Cosmetic Surgery', 'Maxillofacial Surgery',
        ],
        'Orthopaedic Surgery' => ['Spine Surgery', 'Hand Surgery'],
        'Cardiology' => ['Interventional Cardiology', 'Cardiac Electrophysiology'],
        'Gastroenterology' => ['Hepatology'],
        'Endocrinology' => ['Diabetology'],
        'Dermatology' => ['Cosmetic Dermatology'],
        'Oncology' => ['Medical Oncology', 'Radiation Oncology'],
        'Obstetrics and Gynaecology' => [
            'Maternal-Fetal Medicine', 'Reproductive Endocrinology and Infertility', 'Gynaecologic Oncology',
            'Urogynaecology', 'Family Planning', 'Midwifery',
        ],
        'Paediatrics' => [
            'Neonatology', 'Adolescent Medicine', 'Developmental Paediatrics',
            'Paediatric Cardiology', 'Paediatric Neurology', 'Paediatric Endocrinology',
        ],
        'Psychiatry' => ['Child and Adolescent Psychiatry', 'Addiction Medicine'],
        'Psychology' => ['Clinical Psychology', 'Counselling Psychology', 'Neuropsychology'],
        'Radiology' => ['Interventional Radiology', 'Nuclear Medicine'],
        'Pathology' => ['Laboratory Medicine'],
        'Urology' => ['Andrology'],
        'Infectious Diseases' => ['Tropical Medicine', 'Travel Medicine'],
        'Emergency Medicine' => ['Urgent Care', 'Toxicology'],
        'Anaesthesiology' => ['Pain Medicine', 'Critical Care Medicine'],
        'Physical Medicine and Rehabilitation' => ['Physiotherapy', 'Occupational Therapy', 'Speech and Language Therapy'],
        'Otorhinolaryngology (ENT)' => ['Audiology'],
        'Ophthalmology' => ['Optometry'],
    ];

    public function safeUp()
    {
        $this->addColumn(self::TABLE, 'parent_id', $this->integer()->null()->after('id'));
        $this->createIndex('idx-specialization-parent_id', self::TABLE, 'parent_id');
        $this->addForeignKey(
            'fk-specialization-parent_id',
            self::TABLE,
            'parent_id',
            self::TABLE,
            'id',
            'SET NULL', // deleting a main area promotes its children to main areas
            'CASCADE'
        );

        $ids = $this->idsByName();
        $now = time();
        foreach (self::NEW_MAIN_AREAS as $name => $description) {
            if (!isset($ids[mb_strtolower($name)])) {
                $this->insert(self::TABLE, [
                    'specialization' => $name,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $ids[mb_strtolower($name)] = (int) $this->db->getLastInsertID();
            }
        }

        $linked = 0;
        foreach (self::HIERARCHY as $parent => $children) {
            $parentId = $ids[mb_strtolower($parent)] ?? null;
            if ($parentId === null) {
                continue;
            }
            foreach ($children as $child) {
                $childId = $ids[mb_strtolower($child)] ?? null;
                if ($childId !== null && $childId !== $parentId) {
                    $this->update(self::TABLE, ['parent_id' => $parentId], ['id' => $childId]);
                    $linked++;
                }
            }
        }
        echo "    > filed $linked sub-specialties under their main areas\n";
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-specialization-parent_id', self::TABLE);
        $this->dropIndex('idx-specialization-parent_id', self::TABLE);
        $this->dropColumn(self::TABLE, 'parent_id');
        $this->delete(self::TABLE, ['specialization' => array_keys(self::NEW_MAIN_AREAS), 'created_by' => null]);
    }

    /** @return array<string, int> lower-cased name => id */
    private function idsByName()
    {
        $map = [];
        foreach ((new Query())->select(['id', 'specialization'])->from(self::TABLE)->all($this->db) as $row) {
            if ($row['specialization'] !== null) {
                $map[mb_strtolower(trim($row['specialization']))] = (int) $row['id'];
            }
        }
        return $map;
    }
}
