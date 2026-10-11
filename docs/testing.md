# Tests

The suite drives the real application in a real browser through Playwright. It
does not mock anything: it registers participants, starts rounds, types code,
submits, scores and reads the standings.

MySQL is replaced by a SQLite stand-in (`tests/db_sqlite.php`) and a tiny router
(`tests/router.php`) so the pages can run under PHP's built-in server.

```bash
php -r "require 'tests/db_sqlite.php'; db()->exec(file_get_contents('tests/schema.sqlite.sql'));"
ARENA_CHROME=/path/to/chrome-headless-shell php -S 127.0.0.1:8112 tests/router.php &
node tests/autoend.spec.js
```

| Spec | What it proves |
|---|---|
| `autoend.spec.js` | The whole event end to end: two participants register, a round starts, the round stays open while one is still working and ends itself the moment the last entry is in, the finisher stays on the thank-you screen, the console offers scoring. |
| `takeover.spec.js` | Two machines with identical name, email, phone **and** seat. The second is refused and the first is untouched. This is the bug that broke a lab test. |
| `identity.spec.js` | Same email from a different seat, same seat from a different email, lower-case seat spellings, a blank seat, and crash recovery. |
| `rerun.spec.js` | A completed round started a second time keeps running instead of ending instantly on last run's submissions. |
| `library.spec.js` | Rendering a design never deletes an earlier one, and an older design can be put back on a round. |
| `shell.spec.js` | A target made only of `body` rules renders, the browser preview and the server scorer agree, and identical code scores 100.00%. |
| `export.spec.js` | The Excel workbook downloads while the console keeps polling. |

## A trap worth knowing

`chrome-headless-shell` gives a full 400 × 300 viewport. Full Chrome or
Chromium with `--headless=new` does **not** — in a container it reported
400 × **213**, silently losing the bottom third of every render while still
writing a correctly-sized PNG. Set `ARENA_CHROME` to a real
`chrome-headless-shell` binary when running the suite.
