-- ONE-TIME UPGRADE: adds email and phone, and makes the EMAIL the identity
-- instead of the name, so two students called Meet Patel can both register.
--
-- Paste this into the phpMyAdmin SQL tab with `vivacity` selected, and press Go.
-- Only needed on a database created before this change; a fresh import of
-- schema.sql already has it.
--
-- It clears existing participants first. Existing rows have no email, so they
-- cannot satisfy the new rule — and they are rehearsal data anyway.

DELETE FROM qualifiers;
DELETE FROM submissions;
DELETE FROM participants;

ALTER TABLE participants
  ADD COLUMN email VARCHAR(120) NOT NULL AFTER name,
  ADD COLUMN phone VARCHAR(20)  NULL     AFTER email;

ALTER TABLE participants DROP INDEX uq_name;
ALTER TABLE participants ADD UNIQUE KEY uq_email (email);

UPDATE rounds SET status = 'pending', started_at = NULL, ends_at = NULL, published = 0;
UPDATE settings SET v = '0'     WHERE k = 'current_round';
UPDATE settings SET v = 'lobby' WHERE k = 'event_state';
