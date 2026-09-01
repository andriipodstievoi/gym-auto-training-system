<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Product variants, and the orders the shop writes.
 *
 * The order table is customer_order because "order" is a reserved word in
 * MySQL, the same trap billing_interval already worked around.
 *
 * An order line snapshots the name, SKU and unit price it was bought at, and
 * keeps only nullable references back to the product and variant. Repricing or
 * deleting something from the catalogue must never rewrite what a member was
 * charged, which is why the foreign keys are ON DELETE SET NULL.
 */
final class Version20260901002440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_variant, customer_order and customer_order_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_order (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, total_cents INT NOT NULL, email VARCHAR(180) NOT NULL, stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_3B1CE6A3A76ED395 (user_id), INDEX idx_order_user_status (user_id, status), UNIQUE INDEX uniq_order_reference (reference), UNIQUE INDEX uniq_order_checkout_session (stripe_checkout_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_order_item (id INT AUTO_INCREMENT NOT NULL, name_snapshot JSON NOT NULL, sku_snapshot VARCHAR(32) NOT NULL, unit_price_cents INT NOT NULL, quantity INT NOT NULL, order_id INT NOT NULL, product_id INT DEFAULT NULL, variant_id INT DEFAULT NULL, INDEX IDX_AF231B8B8D9F6D38 (order_id), INDEX IDX_AF231B8B4584665A (product_id), INDEX IDX_AF231B8B3B69A9AF (variant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_variant (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(32) NOT NULL, label JSON NOT NULL, price_cents INT NOT NULL, stock INT NOT NULL, position INT NOT NULL, active TINYINT NOT NULL, product_id INT NOT NULL, INDEX IDX_209AA41D4584665A (product_id), INDEX idx_variant_product_position (product_id, position), UNIQUE INDEX uniq_variant_sku (sku), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_order ADD CONSTRAINT FK_3B1CE6A3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_order_item ADD CONSTRAINT FK_AF231B8B8D9F6D38 FOREIGN KEY (order_id) REFERENCES customer_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_order_item ADD CONSTRAINT FK_AF231B8B4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_order_item ADD CONSTRAINT FK_AF231B8B3B69A9AF FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_order DROP FOREIGN KEY FK_3B1CE6A3A76ED395');
        $this->addSql('ALTER TABLE customer_order_item DROP FOREIGN KEY FK_AF231B8B8D9F6D38');
        $this->addSql('ALTER TABLE customer_order_item DROP FOREIGN KEY FK_AF231B8B4584665A');
        $this->addSql('ALTER TABLE customer_order_item DROP FOREIGN KEY FK_AF231B8B3B69A9AF');
        $this->addSql('ALTER TABLE product_variant DROP FOREIGN KEY FK_209AA41D4584665A');
        $this->addSql('DROP TABLE customer_order');
        $this->addSql('DROP TABLE customer_order_item');
        $this->addSql('DROP TABLE product_variant');
    }
}
