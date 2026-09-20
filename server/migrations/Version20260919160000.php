<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give catalogue items an updatedAt so clients can tell what has changed
 * since they last copied it. Existing rows start equal to created_at:
 * nothing has been modified yet, so that is the truthful value.
 */
final class Version20260919160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add updated_at to catalogue_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalogue_item ADD updated_at DATETIME');
        $this->addSql('UPDATE catalogue_item SET updated_at = created_at WHERE updated_at IS NULL');

        if ($this->connection->getDatabasePlatform()->getName() !== 'sqlite') {
            $this->addSql('ALTER TABLE catalogue_item MODIFY updated_at DATETIME NOT NULL');
        }

        $this->addSql('CREATE INDEX idx_item_updated ON catalogue_item (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_item_updated');
        $this->addSql('ALTER TABLE catalogue_item DROP COLUMN updated_at');
    }
}
