<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Soft deletion for catalogue items. Nothing is ever removed: lists people
 * have shared and copies on their devices both point at these rows.
 */
final class Version20260919180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at to catalogue_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalogue_item ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_item_deleted ON catalogue_item (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_item_deleted');
        $this->addSql('ALTER TABLE catalogue_item DROP COLUMN deleted_at');
    }
}
