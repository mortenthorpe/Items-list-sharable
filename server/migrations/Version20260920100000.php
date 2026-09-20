<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per distinct list, instead of one per press of the share button.
 *
 * The column is nullable so lists published before this migration keep
 * working: they simply have no fingerprint and take part in no matching.
 * Every unique index in the supported engines permits repeated NULLs.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fingerprint to shared_list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_list ADD fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_list_fingerprint ON shared_list (fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_list_fingerprint');
        $this->addSql('ALTER TABLE shared_list DROP COLUMN fingerprint');
    }
}
