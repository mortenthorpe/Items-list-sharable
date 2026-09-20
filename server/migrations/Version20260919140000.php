<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared lists. Note again the absence: no owner, no address, no session —
 * the uuid is the only handle a list has.
 */
final class Version20260919140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shared_list and shared_list_item';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $autoIncrement = $platform === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT AUTO_INCREMENT PRIMARY KEY';

        $this->addSql(sprintf(
            'CREATE TABLE shared_list (
                id %s,
                uuid CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL
            )',
            $autoIncrement
        ));
        $this->addSql('CREATE UNIQUE INDEX uniq_list_uuid ON shared_list (uuid)');

        $this->addSql(sprintf(
            'CREATE TABLE shared_list_item (
                id %s,
                list_id INT NOT NULL,
                item_id INT NOT NULL,
                position INT NOT NULL
            )',
            $autoIncrement
        ));
        $this->addSql('CREATE INDEX idx_list ON shared_list_item (list_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_list_item');
        $this->addSql('DROP TABLE shared_list');
    }
}
