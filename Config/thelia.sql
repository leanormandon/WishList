
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- whish_list
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `whish_list`;

CREATE TABLE `whish_list`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `product_id` INTEGER NOT NULL,
    `customer_id` INTEGER NOT NULL,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `idx_wish_list_product_id` (`product_id`),
    INDEX `idx_wish_list_customer_id` (`customer_id`),
    CONSTRAINT `fk_wish_list_product_id`
        FOREIGN KEY (`product_id`)
        REFERENCES `product` (`id`),
    CONSTRAINT `fk_wish_list_customer_id`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
