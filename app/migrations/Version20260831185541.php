<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accounts and the memberships they hold.
 *
 * Also gives trainer the nullable user_id M1 deliberately left off, so a
 * coach can be linked to a login once there is something to sign in for.
 */
final class Version20260831185541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user and user_membership, and link trainer to a user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, locale VARCHAR(5) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_membership (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, price_paid_cents INT NOT NULL, stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, INDEX IDX_21981469A76ED395 (user_id), INDEX IDX_21981469E899029B (plan_id), INDEX idx_membership_user_status (user_id, status), UNIQUE INDEX uniq_membership_checkout_session (stripe_checkout_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_membership ADD CONSTRAINT FK_21981469A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_membership ADD CONSTRAINT FK_21981469E899029B FOREIGN KEY (plan_id) REFERENCES membership_plan (id)');
        $this->addSql('ALTER TABLE trainer ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE trainer ADD CONSTRAINT FK_C5150820A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5150820A76ED395 ON trainer (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_membership DROP FOREIGN KEY FK_21981469A76ED395');
        $this->addSql('ALTER TABLE user_membership DROP FOREIGN KEY FK_21981469E899029B');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE user_membership');
        $this->addSql('ALTER TABLE trainer DROP FOREIGN KEY FK_C5150820A76ED395');
        $this->addSql('DROP INDEX UNIQ_C5150820A76ED395 ON trainer');
        $this->addSql('ALTER TABLE trainer DROP user_id');
    }
}
