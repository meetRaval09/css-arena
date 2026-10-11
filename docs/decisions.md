# Engineering notes

The decisions in this project that were not obvious, and what forced them.

## Identity: liveness, not typed fields

Registration takes a name, email, phone and seat. None of them can prove who
you are, because **two people can type the same thing** — and in a lab, two
people did. The first version used the email as identity, so the second student
to type a shared email silently took over the first one's row *and cookie*, and
the first watched his own name change on his own screen.

The fix was not a better field. It was to stop trusting typed data:

- Email is unique across participants; seat is unique among active ones.
- If a registration's browser has polled within the last 20 seconds, it is
  **in use** and nobody else gets it, whatever they type.
- If it has gone quiet, it is a genuine return — a crashed browser, a cleared
  cookie, a move to a spare machine — and reconnecting restores their saved
  code.

Crash recovery is unharmed because a browser that merely closed and reopened
still has its cookie and never re-registers at all.

A released participant does **not** hold their seat, so a machine can be handed
to someone else. That exception is why seat uniqueness lives in code and not in
a database index.

## The render shell

Every target and every submission is rendered inside the same shell, and the
shell exists in two places that must stay byte-identical: `render_shell()` in
`lib/shoot.php`, which scores, and `wrap()` in `assets/js/editor.js`, which
draws the participant's live preview. If they drift, the preview and the score
disagree and nobody can tell which is right.

`body` is given an explicit 400 × 300 size. With the browser default — auto
height — body is only as tall as its contents, so `box-shadow` on body painted
a strip, and with no elements at all it painted **nothing**. A background hid
the problem, because a body background propagates to the canvas on its own;
box-shadow does not propagate, which is how it surfaced.

This is a deliberate departure from CSS Battle, which keeps the 8px body margin
as part of the puzzle. For students meeting CSS for the first time, an
invisible 8px offset is a cruelty rather than a challenge.

## Rounds end themselves

Waiting for a clock when nobody is still writing is dead time in a hall full of
people. `auto_end_if_done()` compares who the round is waiting on against final
submissions and closes it the moment they match.

The subtlety: it counts only work submitted **at or after the round's current
start time**. Without that, a round started a second time ended instantly on
the previous run's submissions — which would have made a false start on the day
unrecoverable.

## Nothing fetched at runtime

There is no internet in the hall, so anything loaded at runtime would simply
not arrive. The confetti (`assets/js/confetti.js`) and the Excel writer
(`lib/xlsx.php`) are both written by hand for that reason — an `.xlsx` is a zip
of XML parts and PHP ships `ZipArchive`, so no library is needed.

This was verified by denying it: every request the browser made was blocked
unless it went to the local server, and the full competition — registration,
countdown, arena, live accuracy check, submission, scoring, standings —
completed with nothing reaching the outside.

## Phone numbers are text cells

A CSV has no types, so Excel guesses: `09876543210` loses its leading zero and
a `+91` can turn into an error. The workbook writes those cells as text, which
is the whole reason the export is not just a CSV.

## The scoring scale

Pixel comparison uses luminance-weighted colour distance, so a badly wrong
green costs more than a barely noticeable blue, and the raw figure is rescaled
against a blank render of the same design. Doing nothing scores 0%, not the
~35% a blank canvas would otherwise earn for matching the background.

The honest weakness: pixel area is not difficulty. A large flat background is
most of the pixels, so the hardest part of a design is usually worth the least.
Designs are chosen with that in mind — detail spread across the canvas rather
than fine detail on a big flat field.
