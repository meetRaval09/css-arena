














CREATE TABLE IF NOT EXISTS participants (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT  NOT NULL,
  email       TEXT NOT NULL,
  phone       TEXT  NULL,              -- for the organisers' records only
  seat_no     TEXT  NULL,
  token       TEXT     NOT NULL,          -- kept in the browser, used to reconnect
  last_seen   TEXT     NULL,              -- updated by every state poll
  is_active   INTEGER(1)   NOT NULL DEFAULT 1,
  created_at  TEXT     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (token),
  UNIQUE (email)
);




CREATE TABLE IF NOT EXISTS designs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT  NOT NULL,
  for_round    INTEGER      NOT NULL DEFAULT 1,   -- which round it was approved for
  src_path     TEXT NOT NULL,             -- assets/rounds/r1.html  (host only)
  png_path     TEXT NOT NULL,             -- assets/rounds/r1.png   (sent to clients)
  palette_json TEXT NOT NULL DEFAULT '[]',
  hints_json   TEXT         NULL,                 -- shown in rounds 2 and 3 only
  par_chars    INT          NOT NULL DEFAULT 0,   -- our own solution's length
  blank_raw    REAL NOT NULL DEFAULT 0,   -- score of an empty render: the zero point
  canvas_w     INTEGER     NOT NULL DEFAULT 400,
  canvas_h     INTEGER     NOT NULL DEFAULT 300,
  created_at   TEXT     NOT NULL DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS rounds (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  round_no      INTEGER      NOT NULL,
  difficulty    TEXT NOT NULL,
  design_id     INT          NULL,               -- chosen when the round is started
  time_limit_s  INT          NOT NULL DEFAULT 600,
  chars_first   INTEGER(1)   NOT NULL DEFAULT 0, -- 1 in round 3: characters break ties
  qualify_count INT          NOT NULL DEFAULT 0, -- 0 = final round, nobody advances
  status        TEXT NOT NULL DEFAULT 'pending',
  started_at    TEXT     NULL,
  ends_at       TEXT     NULL,
  published     INTEGER(1)   NOT NULL DEFAULT 0, -- results shown on the projector
  UNIQUE (round_no),
  FOREIGN KEY (design_id) REFERENCES designs(id) ON DELETE SET NULL
);




CREATE TABLE IF NOT EXISTS submissions (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  participant_id INT NOT NULL,
  round_id       INT NOT NULL,
  code           TEXT   NOT NULL,
  char_count     INT          NOT NULL DEFAULT 0,   -- comments and their lines excluded
  is_final       INTEGER(1)   NOT NULL DEFAULT 0,   -- 0 = autosaved draft, 1 = submitted
  submitted_at   TEXT     NULL,
  elapsed_ms     INT          NULL,                 -- round start to submit
  extra_time_ms  INT          NOT NULL DEFAULT 0,   -- extra WORKING time after a PC failure
  accuracy       REAL NULL,                 -- rescaled 0-100; this is the ranking value
  raw_score      REAL NULL,                 -- before rescaling, kept for auditing
  exact_pct      REAL NULL,                 -- % of pixels within tolerance
  shot_path      TEXT NULL,
  status         TEXT NOT NULL DEFAULT 'draft',
  error_msg      TEXT NULL,
  updated_at     TEXT     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (participant_id, round_id),
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  FOREIGN KEY (round_id)       REFERENCES rounds(id)       ON DELETE CASCADE
);



CREATE TABLE IF NOT EXISTS qualifiers (
  round_id       INT NOT NULL,
  participant_id INT NOT NULL,
  PRIMARY KEY (round_id, participant_id),
  FOREIGN KEY (round_id)       REFERENCES rounds(id)       ON DELETE CASCADE,
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS settings (
  k TEXT  PRIMARY KEY,
  v TEXT NOT NULL
);



INSERT OR IGNORE INTO settings (k, v) VALUES
  ('event_state',   'lobby'),   -- lobby | running | between | finished
  ('current_round', '0');



INSERT OR IGNORE INTO rounds (round_no, difficulty, time_limit_s, chars_first, qualify_count) VALUES
  (1, 'easy',    600,  0, 30),
  (2, 'medium',  900,  0, 10),
  (3, 'hard',   1200,  1, 0);
