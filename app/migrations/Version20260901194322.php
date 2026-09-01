<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The questionnaire, and the programmes it generates.
 *
 * training_plan.payload keeps the whole TrainingPlan document the service
 * returned rather than shredding it into tables. The engine owns that shape
 * and versions it in engine_version; normalising it here would mean a schema
 * migration every time the engine learned a new field, and would quietly
 * make this side the authority on a structure it does not decide.
 *
 * The eight PAR-Q+ answers are columns rather than a JSON blob because they
 * are a fixed, safety-critical set defined by the contract - not a bag that
 * grows. A missing screening answer should be a schema error, not a null key.
 */
final class Version20260901194322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add assessment and training_plan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assessment (id INT AUTO_INCREMENT NOT NULL, age INT NOT NULL, height_cm INT NOT NULL, weight_kg DOUBLE PRECISION NOT NULL, goal VARCHAR(20) NOT NULL, experience VARCHAR(20) NOT NULL, equipment VARCHAR(20) NOT NULL, days_per_week INT NOT NULL, minutes_per_session INT NOT NULL, limitations JSON NOT NULL, disliked_exercises JSON NOT NULL, heart_condition TINYINT NOT NULL, chest_pain TINYINT NOT NULL, dizziness_or_fainting TINYINT NOT NULL, bone_or_joint_problem TINYINT NOT NULL, blood_pressure_medication TINYINT NOT NULL, recent_surgery TINYINT NOT NULL, pregnancy TINYINT NOT NULL, other_reason_not_to_exercise TINYINT NOT NULL, locale VARCHAR(5) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_F7523D70A76ED395 (user_id), INDEX idx_assessment_user_created (user_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE training_plan (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, engine_version VARCHAR(32) NOT NULL, llm_used TINYINT NOT NULL, split VARCHAR(64) NOT NULL, payload JSON NOT NULL, red_flags JSON NOT NULL, created_at DATETIME NOT NULL, assessment_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_D2C01C3EDD3DD5F1 (assessment_id), INDEX IDX_D2C01C3EA76ED395 (user_id), INDEX idx_training_plan_user_created (user_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assessment ADD CONSTRAINT FK_F7523D70A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_plan ADD CONSTRAINT FK_D2C01C3EDD3DD5F1 FOREIGN KEY (assessment_id) REFERENCES assessment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_plan ADD CONSTRAINT FK_D2C01C3EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessment DROP FOREIGN KEY FK_F7523D70A76ED395');
        $this->addSql('ALTER TABLE training_plan DROP FOREIGN KEY FK_D2C01C3EDD3DD5F1');
        $this->addSql('ALTER TABLE training_plan DROP FOREIGN KEY FK_D2C01C3EA76ED395');
        $this->addSql('DROP TABLE assessment');
        $this->addSql('DROP TABLE training_plan');
    }
}
