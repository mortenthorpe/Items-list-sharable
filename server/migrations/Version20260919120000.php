<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalogue tables. Note there is no users table and no foreign key to one:
 * the schema has no place to put a person even if a later change wanted to.
 */
final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalogue_item, product_code and item_lookup_key';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        $autoIncrement = $platform === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT AUTO_INCREMENT PRIMARY KEY';

        $this->addSql(sprintf(
            'CREATE TABLE catalogue_item (
                id %s,
                uuid CHAR(36) NOT NULL,
                name VARCHAR(120) NOT NULL,
                origin VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL
            )',
            $autoIncrement
        ));
        $this->addSql('CREATE UNIQUE INDEX uniq_item_uuid ON catalogue_item (uuid)');

        // The code exactly as printed. An item may carry several.
        $this->addSql(sprintf(
            'CREATE TABLE product_code (
                id %s,
                item_id INT NOT NULL,
                kind VARCHAR(16) NOT NULL,
                value VARCHAR(255) NOT NULL
            )',
            $autoIncrement
        ));
        $this->addSql('CREATE UNIQUE INDEX uniq_item_value ON product_code (item_id, value)');
        $this->addSql('CREATE INDEX idx_code_item ON product_code (item_id)');

        /*
         * Canonical identifiers. Several printed codes reduce to one key —
         * an EAN-13 and the digital link wrapping the same GTIN — so the key
         * cannot live on product_code without forbidding that pairing. Here
         * the unique index means a scan resolves to exactly one product.
         */
        $this->addSql(sprintf(
            'CREATE TABLE item_lookup_key (
                id %s,
                item_id INT NOT NULL,
                lookup_key VARCHAR(255) NOT NULL
            )',
            $autoIncrement
        ));
        $this->addSql('CREATE UNIQUE INDEX uniq_lookup_key ON item_lookup_key (lookup_key)');
        $this->addSql('CREATE INDEX idx_key_item ON item_lookup_key (item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE item_lookup_key');
        $this->addSql('DROP TABLE product_code');
        $this->addSql('DROP TABLE catalogue_item');
    }
}
