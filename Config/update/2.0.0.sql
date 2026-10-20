SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- geolocated_delivery_radius
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `geolocated_delivery_radius`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `min_radius` INTEGER NOT NULL,
    `max_radius` INTEGER NOT NULL,
    `price` FLOAT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- geolocated_delivery_store
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `geolocated_delivery_store`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `street` VARCHAR(255) NOT NULL,
    `zip_code` VARCHAR(255) NOT NULL,
    `city` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `latitude` VARCHAR(255),
    `longitude` VARCHAR(255),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
