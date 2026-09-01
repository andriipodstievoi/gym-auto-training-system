<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trainer availability, session bookings and member-to-coach messaging.
 *
 * booking.held_slot_at mirrors starts_at while a booking still occupies its
 * hour and is null once it does not, so the unique index on
 * (trainer_id, held_slot_at) stops two live bookings sharing a slot while
 * letting a cancelled or declined hour go back on sale. MySQL permits any
 * number of nulls in a unique index, which is what makes that work.
 *
 * Times are stored UTC and presented in Europe/Riga. trainer_availability
 * holds naive local start_time/end_time - a coach saying "09:00" means Riga
 * wall clock, and that stays true across both clock changes a year.
 */
final class Version20260901051943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trainer_availability, booking, conversation and message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL, held_slot_at DATETIME DEFAULT NULL, price_paid_cents INT NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, responded_at DATETIME DEFAULT NULL, trainer_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_E00CEDDEFB08EDF6 (trainer_id), INDEX IDX_E00CEDDEA76ED395 (user_id), INDEX idx_booking_user_start (user_id, starts_at), INDEX idx_booking_trainer_status (trainer_id, status), UNIQUE INDEX uniq_booking_trainer_held_slot (trainer_id, held_slot_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, last_message_at DATETIME NOT NULL, trainer_id INT NOT NULL, member_id INT NOT NULL, INDEX IDX_8A8E26E9FB08EDF6 (trainer_id), INDEX IDX_8A8E26E97597D3FE (member_id), UNIQUE INDEX uniq_conversation_pair (trainer_id, member_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, sent_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, conversation_id INT NOT NULL, sender_id INT NOT NULL, INDEX IDX_B6BD307F9AC0396 (conversation_id), INDEX IDX_B6BD307FF624B39D (sender_id), INDEX idx_message_conversation_sent (conversation_id, sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trainer_availability (id INT AUTO_INCREMENT NOT NULL, weekday SMALLINT NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, active TINYINT NOT NULL, trainer_id INT NOT NULL, INDEX IDX_91A95071FB08EDF6 (trainer_id), INDEX idx_availability_trainer_weekday (trainer_id, weekday), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEFB08EDF6 FOREIGN KEY (trainer_id) REFERENCES trainer (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9FB08EDF6 FOREIGN KEY (trainer_id) REFERENCES trainer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E97597D3FE FOREIGN KEY (member_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FF624B39D FOREIGN KEY (sender_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trainer_availability ADD CONSTRAINT FK_91A95071FB08EDF6 FOREIGN KEY (trainer_id) REFERENCES trainer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEFB08EDF6');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEA76ED395');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E9FB08EDF6');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E97597D3FE');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F9AC0396');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FF624B39D');
        $this->addSql('ALTER TABLE trainer_availability DROP FOREIGN KEY FK_91A95071FB08EDF6');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE trainer_availability');
    }
}
