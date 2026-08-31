<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every floor zone a storey and a kind, so a branch can put its lounge
 * and spa upstairs and the plan can colour a changing room differently from a
 * lifting platform.
 */
final class Version20260831142823 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add floor and kind to floor_zone';
    }

    public function up(Schema $schema): void
    {
        // Added nullable and backfilled before being tightened: adding them
        // NOT NULL outright leaves existing rows with an empty kind, which is
        // not a value the enum can hydrate.
        $this->addSql('ALTER TABLE floor_zone ADD floor INT DEFAULT NULL, ADD kind VARCHAR(16) DEFAULT NULL');
        $this->addSql("UPDATE floor_zone SET floor = 0, kind = 'training'");
        $this->addSql('ALTER TABLE floor_zone CHANGE floor floor INT NOT NULL, CHANGE kind kind VARCHAR(16) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE floor_zone DROP floor, DROP kind');
    }
}
