<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260830185104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE branch (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, description JSON DEFAULT NULL, address_line VARCHAR(180) NOT NULL, city VARCHAR(80) NOT NULL, postal_code VARCHAR(16) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, phone VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, opening_hours JSON NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX uniq_branch_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment (id INT AUTO_INCREMENT NOT NULL, name JSON NOT NULL, type VARCHAR(32) NOT NULL, quantity INT NOT NULL, zone_id INT NOT NULL, INDEX IDX_D338D5839F2C3FAB (zone_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercise (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(80) NOT NULL, name JSON NOT NULL, instructions JSON DEFAULT NULL, primary_muscle VARCHAR(32) NOT NULL, secondary_muscles JSON NOT NULL, pattern VARCHAR(32) NOT NULL, equipment VARCHAR(32) NOT NULL, contraindications JSON NOT NULL, difficulty SMALLINT NOT NULL, active TINYINT NOT NULL, INDEX idx_exercise_selection (primary_muscle, equipment, active), UNIQUE INDEX uniq_exercise_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE floor_zone (id INT AUTO_INCREMENT NOT NULL, svg_id VARCHAR(64) NOT NULL, name JSON NOT NULL, description JSON DEFAULT NULL, position INT NOT NULL, branch_id INT NOT NULL, INDEX IDX_8C170B21DCD6CC49 (branch_id), UNIQUE INDEX uniq_zone_branch_svg (branch_id, svg_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE membership_plan (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, name JSON NOT NULL, description JSON DEFAULT NULL, price_cents INT NOT NULL, billing_interval VARCHAR(16) NOT NULL, features JSON NOT NULL, all_branches TINYINT NOT NULL, active TINYINT NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_plan_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(80) NOT NULL, sku VARCHAR(32) NOT NULL, name JSON NOT NULL, description JSON DEFAULT NULL, price_cents INT NOT NULL, stock INT NOT NULL, image_path VARCHAR(255) DEFAULT NULL, active TINYINT NOT NULL, category_id INT DEFAULT NULL, INDEX IDX_D34A04AD12469DE2 (category_id), UNIQUE INDEX uniq_product_slug (slug), UNIQUE INDEX uniq_product_sku (sku), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_category (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, name JSON NOT NULL, kind VARCHAR(32) NOT NULL, position INT NOT NULL, UNIQUE INDEX uniq_category_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trainer (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, full_name VARCHAR(120) NOT NULL, bio JSON DEFAULT NULL, photo_path VARCHAR(255) DEFAULT NULL, specialities JSON NOT NULL, languages JSON NOT NULL, hourly_rate_cents INT NOT NULL, active TINYINT NOT NULL, branch_id INT DEFAULT NULL, INDEX IDX_C5150820DCD6CC49 (branch_id), UNIQUE INDEX uniq_trainer_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE equipment ADD CONSTRAINT FK_D338D5839F2C3FAB FOREIGN KEY (zone_id) REFERENCES floor_zone (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE floor_zone ADD CONSTRAINT FK_8C170B21DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES product_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE trainer ADD CONSTRAINT FK_C5150820DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipment DROP FOREIGN KEY FK_D338D5839F2C3FAB');
        $this->addSql('ALTER TABLE floor_zone DROP FOREIGN KEY FK_8C170B21DCD6CC49');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE trainer DROP FOREIGN KEY FK_C5150820DCD6CC49');
        $this->addSql('DROP TABLE branch');
        $this->addSql('DROP TABLE equipment');
        $this->addSql('DROP TABLE exercise');
        $this->addSql('DROP TABLE floor_zone');
        $this->addSql('DROP TABLE membership_plan');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_category');
        $this->addSql('DROP TABLE trainer');
    }
}
