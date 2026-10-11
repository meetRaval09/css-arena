-- CSS Arena — database schema
-- Run once in phpMyAdmin, or:  mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS vivacity
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vivacity;

-- NOTE: no CREATE USER / GRANT / FLUSH PRIVILEGES in this file, on purpose.
-- Those need admin rights and abort the whole import on a stock XAMPP — and
-- phpMyAdmin still shows a green success message, so you end up with a
-- database containing no tables at all and no idea why.
-- If you want a dedicated database user, run sql/create-user.sql separately.


-- The email is the identity, not the name. Two students called Meet Patel are
-- two different people; the same email twice is the same person coming back.
CREATE TABLE IF NOT EXISTS participants (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  email       VARCHAR(120) NOT NULL,
  phone       VARCHAR(20)  NULL,              -- for the organisers' records only
  seat_no     VARCHAR(20)  NULL,
  token       CHAR(32)     NOT NULL,          -- kept in the browser, used to reconnect
  last_seen   DATETIME     NULL,              -- updated by every state poll
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB;


-- An approved design. The PNG is what participants receive; src_path stays on
-- this machine and is never served to a browser.
CREATE TABLE IF NOT EXISTS designs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80)  NOT NULL,
  for_round    TINYINT      NOT NULL DEFAULT 1,   -- which round it was approved for
  src_path     VARCHAR(160) NOT NULL,             -- assets/rounds/r1.html  (host only)
  png_path     VARCHAR(160) NOT NULL,             -- assets/rounds/r1.png   (sent to clients)
  palette_json VARCHAR(255) NOT NULL DEFAULT '[]',
  hints_json   TEXT         NULL,                 -- shown in rounds 2 and 3 only
  par_chars    INT          NOT NULL DEFAULT 0,   -- our own solution's length
  blank_raw    DECIMAL(6,3) NOT NULL DEFAULT 0,   -- score of an empty render: the zero point
  canvas_w     SMALLINT     NOT NULL DEFAULT 400,
  canvas_h     SMALLINT     NOT NULL DEFAULT 300,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS rounds (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  round_no      TINYINT      NOT NULL,
  difficulty    ENUM('easy','medium','hard') NOT NULL,
  design_id     INT          NULL,               -- chosen when the round is started
  time_limit_s  INT          NOT NULL DEFAULT 600,
  chars_first   TINYINT(1)   NOT NULL DEFAULT 0, -- 1 in round 3: characters break ties
  qualify_count INT          NOT NULL DEFAULT 0, -- 0 = final round, nobody advances
  status        ENUM('pending','running','ended') NOT NULL DEFAULT 'pending',
  started_at    DATETIME     NULL,
  ends_at       DATETIME     NULL,
  published     TINYINT(1)   NOT NULL DEFAULT 0, -- results shown on the projector
  UNIQUE KEY uq_round (round_no),
  FOREIGN KEY (design_id) REFERENCES designs(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- One row per participant per round. The autosave writes here too, so a crash
-- never costs more than five seconds.
CREATE TABLE IF NOT EXISTS submissions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  participant_id INT NOT NULL,
  round_id       INT NOT NULL,
  code           MEDIUMTEXT   NOT NULL,
  char_count     INT          NOT NULL DEFAULT 0,   -- comments and their lines excluded
  is_final       TINYINT(1)   NOT NULL DEFAULT 0,   -- 0 = autosaved draft, 1 = submitted
  submitted_at   DATETIME     NULL,
  elapsed_ms     INT          NULL,                 -- round start to submit
  extra_time_ms  INT          NOT NULL DEFAULT 0,   -- extra WORKING time after a PC failure
  accuracy       DECIMAL(6,3) NULL,                 -- rescaled 0-100; this is the ranking value
  raw_score      DECIMAL(6,3) NULL,                 -- before rescaling, kept for auditing
  exact_pct      DECIMAL(6,3) NULL,                 -- % of pixels within tolerance
  shot_path      VARCHAR(160) NULL,
  status         ENUM('draft','queued','scoring','scored','error') NOT NULL DEFAULT 'draft',
  error_msg      VARCHAR(255) NULL,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_round (participant_id, round_id),
  KEY k_queue (status),
  KEY k_round (round_id),
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  FOREIGN KEY (round_id)       REFERENCES rounds(id)       ON DELETE CASCADE
) ENGINE=InnoDB;


-- Written when the organiser confirms who goes through.
CREATE TABLE IF NOT EXISTS qualifiers (
  round_id       INT NOT NULL,
  participant_id INT NOT NULL,
  PRIMARY KEY (round_id, participant_id),
  FOREIGN KEY (round_id)       REFERENCES rounds(id)       ON DELETE CASCADE,
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(40)  PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;


-- seed ------------------------------------------------------------------
INSERT IGNORE INTO settings (k, v) VALUES
  ('event_state',   'lobby'),   -- lobby | running | between | finished
  ('current_round', '0');

-- Sized for 60 PCs: 60 -> 30 -> 10 -> winner.
-- qualify_count 0 marks the final.
INSERT IGNORE INTO rounds (round_no, difficulty, time_limit_s, chars_first, qualify_count) VALUES
  (1, 'easy',   1200,  0, 30),   -- 20 minutes
  (2, 'medium', 1200,  0, 10),   -- 20 minutes
  (3, 'hard',   1800,  1, 0);    -- 30 minutes
