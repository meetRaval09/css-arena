-- OPTIONAL, and not needed for the event.
-- Run this on its own, AFTER schema.sql has created the tables.
-- If it errors, ignore it and leave config.php connecting as root —
-- the database is not on the network, so root with no password is fine here.

CREATE USER IF NOT EXISTS 'vivacity'@'localhost' IDENTIFIED BY 'change-me-before-the-event';
GRANT SELECT, INSERT, UPDATE, DELETE ON vivacity.* TO 'vivacity'@'localhost';
FLUSH PRIVILEGES;
