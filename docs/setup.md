# Setting it up

Everything runs on one machine — the **host PC**. Every other machine needs
nothing but Chrome and the host's address. No internet at any point.

## Host PC

1. **XAMPP** — start Apache and MySQL.
2. **Enable GD.** XAMPP → Config → `php.ini`, remove the `;` from
   `;extension=gd`, save, then stop and start Apache. Saving alone does nothing.
3. **Copy this folder** to `C:\xampp\htdocs\vivacity`.
4. **The renderer.** Download `chrome-headless-shell` for your platform and put
   it at `C:\xampp\chrome-headless-shell\chrome-headless-shell.exe`.
   The archive nests a folder inside a folder of the same name — copy the
   *inner* one, the one with the `.exe` directly inside it.
5. **Config.** Copy `config.example.php` to `config.php`, then set
   `ADMIN_PASSWORD_HASH` and `RENDER_SECRET`:
   ```
   php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
   php -r "echo bin2hex(random_bytes(24));"
   ```
   Leave `CHROME_PATH` empty to auto-detect the location in step 4.
6. **Database.** phpMyAdmin → **New** → name it `vivacity` → the **SQL** tab →
   paste `sql/schema.sql` → Go.

   Use the **SQL** tab, not **Import**. Import stops at the first thing it
   dislikes, leaves you with a database containing no tables, and still shows a
   green success message.
7. **Self-test.** Open `http://localhost/vivacity/spike.php` if you have it, or
   just attach a design and check it renders. Nothing else will work until the
   renderer does.

## On the network

```
ipconfig            → note the IPv4 address, e.g. 192.168.1.20
```

Allow Apache through the Windows firewall on port 80. Participants open
`http://192.168.1.20/vivacity`.

**Then prove the lock works.** From a participant machine, try
`/vivacity/admin/` and `/vivacity/results.php`. Both must be refused. If either
opens, stop and fix it before running anything — a participant who finds the
console can start and end rounds.

## Participant machines

Nothing to install. Open Chrome, go to the address, leave it on the
registration screen. F11 for full screen helps. They hold no data, so a dead
machine can be swapped for another in seconds.

## Loading designs

Admin console → **Round designs** → paste the HTML and CSS → **Render and
attach**. You see the rendered picture, the colours pulled out of it, your own
solution's character count, and the blank baseline.

Only the rendered PNG ever reaches a participant. The source is written into
`storage/`, which the web server refuses to serve, so View Source and DevTools
on a participant machine show nothing useful.

Every design you render is kept in the library on that page and can be
re-attached to any round later.

## Running the event

```
Start Round N → they work → the round ends itself when the last entry is in
     → Score this round → Standings → Confirm who advances
```

**A finished round is not a scored round.** Until you press **Score this
round**, the standings show every entry as `queued`. The page says so.
