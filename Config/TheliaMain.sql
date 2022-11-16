
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- geolocated_delivery_radius
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `geolocated_delivery_radius`;

CREATE TABLE `geolocated_delivery_radius`
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

DROP TABLE IF EXISTS `geolocated_delivery_store`;

CREATE TABLE `geolocated_delivery_store`
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

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
