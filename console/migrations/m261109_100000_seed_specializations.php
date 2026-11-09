<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Seeds the `{{%specialization}}` table with common medical specializations.
 *
 * Names that already exist (case-insensitive) are skipped, so admin-entered rows are kept.
 * Seeded rows have created_by = NULL; safeDown() only removes those, so rows created through
 * the admin UI (which records created_by) survive a rollback.
 */
class m261109_100000_seed_specializations extends Migration
{
    public function safeUp()
    {
        $existing = array_map('mb_strtolower', (new Query())
            ->select('specialization')
            ->from('{{%specialization}}')
            ->column($this->db));

        $now = time();
        $rows = [];
        foreach (self::specializations() as $name => $description) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            $rows[] = [$name, $description, $now, $now, null, null];
        }

        if ($rows) {
            $this->batchInsert(
                '{{%specialization}}',
                ['specialization', 'description', 'created_at', 'updated_at', 'created_by', 'updated_by'],
                $rows
            );
        }
        echo '    > seeded ' . count($rows) . ' specialization(s), skipped ' . (count(self::specializations()) - count($rows)) . " existing\n";
    }

    public function safeDown()
    {
        $this->delete('{{%specialization}}', [
            'specialization' => array_keys(self::specializations()),
            'created_by' => null,
        ]);
    }

    /**
     * @return array<string, string> specialization => description
     */
    private static function specializations()
    {
        return [
            // ── Primary & general care ──
            'General Practice' => 'First point of contact for patients of all ages, diagnosing and treating common illnesses and referring to specialists when needed.',
            'Family Medicine' => 'Continuous, comprehensive care for individuals and families across all ages, sexes and diseases.',
            'Internal Medicine' => 'Prevention, diagnosis and non-surgical treatment of adult diseases affecting internal organs.',
            'Paediatrics' => 'Medical care of infants, children and adolescents, including growth, development and childhood illnesses.',
            'Geriatrics' => 'Health care of older adults, focusing on age-related conditions, mobility, cognition and multiple medications.',
            'Adolescent Medicine' => 'Physical, emotional and reproductive health care for young people aged roughly 10 to 21.',
            'Emergency Medicine' => 'Immediate assessment and treatment of acute illnesses and injuries requiring urgent attention.',
            'Urgent Care' => 'Walk-in treatment of illnesses and minor injuries that need prompt but not emergency care.',
            'Hospital Medicine' => 'Care of patients admitted to hospital, coordinating treatment from admission to discharge.',
            'Preventive Medicine' => 'Disease prevention and health promotion through screening, vaccination and lifestyle counselling.',

            // ── Medical specialties ──
            'Cardiology' => 'Diagnosis and treatment of heart and blood vessel conditions such as hypertension, heart failure and arrhythmias.',
            'Interventional Cardiology' => 'Catheter-based procedures such as angioplasty and stenting to treat narrowed heart arteries.',
            'Cardiac Electrophysiology' => 'Diagnosis and treatment of heart rhythm disorders, including pacemakers and ablation.',
            'Pulmonology' => 'Diseases of the lungs and airways, including asthma, COPD, tuberculosis and pneumonia.',
            'Gastroenterology' => 'Disorders of the digestive system, including the oesophagus, stomach, intestines, liver and pancreas.',
            'Hepatology' => 'Diseases of the liver, gallbladder and biliary tree, such as hepatitis and cirrhosis.',
            'Nephrology' => 'Kidney diseases, electrolyte disorders, hypertension and dialysis management.',
            'Endocrinology' => 'Hormone and gland disorders such as diabetes, thyroid disease and adrenal conditions.',
            'Diabetology' => 'Specialised management of type 1, type 2 and gestational diabetes and their complications.',
            'Rheumatology' => 'Autoimmune and inflammatory conditions of joints, muscles and connective tissue, such as arthritis and lupus.',
            'Haematology' => 'Blood disorders, including anaemia, clotting disorders, sickle cell disease and blood cancers.',
            'Medical Oncology' => 'Diagnosis and treatment of cancer using chemotherapy, immunotherapy and targeted therapy.',
            'Radiation Oncology' => 'Treatment of cancer and some benign conditions using controlled doses of radiation.',
            'Infectious Diseases' => 'Diagnosis and management of complex infections, including HIV, tuberculosis, malaria and tropical diseases.',
            'Allergy and Immunology' => 'Allergies, asthma and immune system disorders, including allergy testing and immunotherapy.',
            'Neurology' => 'Disorders of the brain, spinal cord and nerves, such as epilepsy, stroke, migraine and Parkinson\'s disease.',
            'Dermatology' => 'Conditions of the skin, hair and nails, including acne, eczema, psoriasis and skin cancer.',
            'Cosmetic Dermatology' => 'Aesthetic skin treatments such as chemical peels, laser therapy and injectables.',
            'Sleep Medicine' => 'Diagnosis and treatment of sleep disorders such as insomnia, sleep apnoea and narcolepsy.',
            'Sports Medicine' => 'Prevention and treatment of injuries related to sport and exercise, and performance optimisation.',
            'Pain Medicine' => 'Assessment and management of acute and chronic pain using medication, procedures and therapy.',
            'Palliative Care' => 'Relief of symptoms and stress for people with serious illness, focusing on quality of life.',
            'Occupational Medicine' => 'Health and safety of workers, workplace injuries, fitness-to-work assessments and occupational diseases.',
            'Travel Medicine' => 'Pre-travel advice, vaccinations and care of illnesses acquired while travelling.',
            'Tropical Medicine' => 'Diseases prevalent in tropical regions, such as malaria, schistosomiasis and leishmaniasis.',
            'Clinical Genetics' => 'Diagnosis and counselling for inherited conditions and genetic testing.',
            'Clinical Pharmacology' => 'Safe and effective use of medicines, drug interactions and medication reviews.',
            'Toxicology' => 'Diagnosis and treatment of poisoning, overdoses and exposure to harmful substances.',
            'Critical Care Medicine' => 'Care of critically ill patients in intensive care units, including life support.',
            'Nuclear Medicine' => 'Use of radioactive substances to diagnose and treat diseases, such as thyroid and bone scans.',

            // ── Surgical specialties ──
            'General Surgery' => 'Surgical treatment of abdominal organs, hernias, breast, skin and soft tissue conditions.',
            'Cardiothoracic Surgery' => 'Surgery of the heart, lungs, oesophagus and chest, including bypass and valve surgery.',
            'Vascular Surgery' => 'Surgical and minimally invasive treatment of arteries and veins, such as aneurysms and varicose veins.',
            'Neurosurgery' => 'Surgery of the brain, spine and peripheral nerves, including tumours, trauma and disc disease.',
            'Orthopaedic Surgery' => 'Surgical and non-surgical care of bones, joints, ligaments, tendons and muscles.',
            'Spine Surgery' => 'Surgical treatment of spinal conditions such as herniated discs, stenosis and deformities.',
            'Hand Surgery' => 'Surgery of the hand, wrist and forearm, including fractures, tendon injuries and carpal tunnel.',
            'Plastic and Reconstructive Surgery' => 'Restoration of form and function after injury, cancer, burns or congenital defects.',
            'Cosmetic Surgery' => 'Elective surgery to enhance appearance, such as rhinoplasty, liposuction and facelifts.',
            'Paediatric Surgery' => 'Surgical care of infants, children and adolescents, including congenital anomalies.',
            'Colorectal Surgery' => 'Surgery of the colon, rectum and anus, including haemorrhoids, fistulas and bowel cancer.',
            'Hepatobiliary Surgery' => 'Surgery of the liver, gallbladder, bile ducts and pancreas.',
            'Bariatric Surgery' => 'Weight-loss surgery such as gastric bypass and sleeve gastrectomy for severe obesity.',
            'Breast Surgery' => 'Surgical management of breast lumps, breast cancer and other breast conditions.',
            'Endocrine Surgery' => 'Surgery of the thyroid, parathyroid and adrenal glands.',
            'Surgical Oncology' => 'Surgical removal of tumours and cancer staging procedures.',
            'Trauma Surgery' => 'Surgical care of patients with severe injuries from accidents, falls or violence.',
            'Transplant Surgery' => 'Organ transplantation such as kidney and liver transplants and donor care.',
            'Urology' => 'Conditions of the urinary tract and male reproductive organs, including stones and prostate disease.',
            'Andrology' => 'Male reproductive and sexual health, including infertility and erectile dysfunction.',
            'Otorhinolaryngology (ENT)' => 'Disorders of the ear, nose, throat, head and neck, including sinusitis and hearing loss.',
            'Ophthalmology' => 'Eye diseases and surgery, including cataracts, glaucoma and retinal disorders.',
            'Maxillofacial Surgery' => 'Surgery of the face, jaws and mouth, including facial trauma and corrective jaw surgery.',
            'Anaesthesiology' => 'Anaesthesia, sedation and pain control before, during and after surgery.',

            // ── Women's & reproductive health ──
            'Obstetrics and Gynaecology' => 'Pregnancy, childbirth and the health of the female reproductive system.',
            'Maternal-Fetal Medicine' => 'Care of high-risk pregnancies and monitoring of the unborn baby.',
            'Reproductive Endocrinology and Infertility' => 'Fertility assessment and treatment, including IVF, and hormonal reproductive disorders.',
            'Gynaecologic Oncology' => 'Diagnosis and treatment of cancers of the female reproductive organs.',
            'Urogynaecology' => 'Pelvic floor disorders in women, such as incontinence and pelvic organ prolapse.',
            'Neonatology' => 'Medical care of newborns, especially premature or critically ill infants.',
            'Midwifery' => 'Care during pregnancy, labour and the postnatal period for low-risk mothers and babies.',
            'Family Planning' => 'Contraception counselling, provision and reproductive health planning.',

            // ── Mental health ──
            'Psychiatry' => 'Diagnosis and treatment of mental illness, including medication management.',
            'Child and Adolescent Psychiatry' => 'Mental health care for children and teenagers, including ADHD, anxiety and behavioural disorders.',
            'Addiction Medicine' => 'Treatment of substance use disorders and behavioural addictions, including detoxification.',
            'Clinical Psychology' => 'Psychological assessment and talking therapies for emotional and behavioural problems.',
            'Counselling Psychology' => 'Talk-based support for life stress, relationships, grief and personal challenges.',
            'Neuropsychology' => 'Assessment of how brain injury or disease affects memory, thinking and behaviour.',

            // ── Paediatric subspecialties ──
            'Paediatric Cardiology' => 'Heart conditions in children, including congenital heart defects.',
            'Paediatric Neurology' => 'Brain, nerve and muscle disorders in children, such as epilepsy and developmental delays.',
            'Paediatric Endocrinology' => 'Growth, puberty, diabetes and hormone disorders in children.',
            'Developmental Paediatrics' => 'Assessment and support for children with developmental, learning and behavioural differences.',

            // ── Diagnostics ──
            'Radiology' => 'Diagnosis using medical imaging such as X-ray, ultrasound, CT and MRI.',
            'Interventional Radiology' => 'Image-guided minimally invasive procedures such as biopsies, drainage and embolisation.',
            'Pathology' => 'Diagnosis of disease through examination of tissues, cells and body fluids.',
            'Laboratory Medicine' => 'Clinical laboratory testing of blood and other samples to support diagnosis and monitoring.',

            // ── Dental & oral health ──
            'General Dentistry' => 'Routine dental check-ups, cleaning, fillings and prevention of oral disease.',
            'Orthodontics' => 'Correction of misaligned teeth and jaws using braces and aligners.',
            'Periodontics' => 'Prevention and treatment of gum disease and placement of dental implants.',
            'Endodontics' => 'Treatment of the dental pulp and roots, including root canal therapy.',
            'Prosthodontics' => 'Restoration and replacement of teeth with crowns, bridges, dentures and implants.',
            'Paediatric Dentistry' => 'Dental care for infants, children and adolescents.',

            // ── Rehabilitation & allied health ──
            'Physical Medicine and Rehabilitation' => 'Restoring function after injury, stroke or illness affecting the nerves, muscles and bones.',
            'Physiotherapy' => 'Exercise, manual therapy and movement rehabilitation to restore mobility and reduce pain.',
            'Occupational Therapy' => 'Helping people regain independence in daily activities after illness, injury or disability.',
            'Speech and Language Therapy' => 'Assessment and treatment of speech, language, voice and swallowing difficulties.',
            'Audiology' => 'Hearing and balance assessment, hearing aids and tinnitus management.',
            'Optometry' => 'Eye examinations, vision testing and prescription of glasses and contact lenses.',
            'Nutrition and Dietetics' => 'Diet planning and nutritional therapy for health, weight management and chronic disease.',
            'Podiatry' => 'Diagnosis and treatment of foot and ankle conditions, including diabetic foot care.',
            'Chiropractic' => 'Manual treatment of musculoskeletal problems, particularly of the spine.',
            'Wound Care' => 'Management of chronic and complex wounds such as ulcers, burns and surgical wounds.',
        ];
    }
}
