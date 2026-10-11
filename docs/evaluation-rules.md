# Evaluation rules

**The best design wins. Time is a limit, not a score.**

## Every round

| | |
|---|---|
| Canvas | 400 × 300 pixels |
| Allowed | HTML and CSS only. JavaScript never runs when the server renders a submission, so the rule enforces itself — nobody is disqualified for it. |
| Characters | Whitespace counts. Comments are free, including the whole line they sit on. |
| Palette | The design's colours are read off the rendered target and shown on screen |
| Check accuracy | Available during the round, any number of times, rate-limited per person |
| Timer | Runs on the server, identical on every PC regardless of the local clock |
| Autosave | Every 5 seconds. If the clock runs out, the last save is submitted automatically. |
| Scoring | The server renders the submission and compares it pixel by pixel with the target |
| Scale | Blank canvas = 0%. Pixel perfect = 100%. |
| Rounding | Accuracy is rounded to the nearest 0.1% before ranking |
| Time | Recorded, but not used to rank in rounds 1 and 2 |

**Why 0.1%.** Two submissions that look identical still differ by about 0.05%
because of anti-aliasing. A real one-pixel mistake costs about 0.13%. Rounding
at 0.1% sits between the two, so entries that look the same tie honestly and
entries that are genuinely different get separated.

**Why the blank baseline matters.** An empty submission already shares a lot of
colour with any target — against one design a blank white canvas scored 36% raw.
The blank render is measured once per design and used as the zero point, so
doing nothing earns nothing and the whole scale in between belongs to real work.

## Rounds 1 and 2

- **Ranked by** accuracy alone. Equal accuracy is an equal place.
- **Who advances:** the top N, **plus everyone tied with the last qualifying
  place**, and only if they scored **60% or more**.

The 60% floor is a "did you attempt this" gate, not a quality bar. Nothing
realistic lands near it, so the line never cuts anyone by a hair.

## Round 3 — the final

Ranked in order:

1. Accuracy, rounded to 0.1%
2. Fewer characters
3. Earlier submission
4. Still tied → joint placing

**No minimum score to win.** If the final design turned out too hard for
everyone, that is the organisers' fault, not the participants'.

If two entries match on accuracy *and* character count they are probably the
same code. Every submission is stored — open both and compare.

## When a PC fails

Give that participant **extra working time**, not a score adjustment. Their own
deadline moves; nobody else's does. Extending the round for everyone would hand
the same free minutes to the participants whose machines were fine.

## Placings

Competition ranking: 1, 2, 2, 4. Where the qualifying cut lands inside a tie,
the whole tie advances — nobody is dropped by something that is not their
design.

## As run

| Round | Difficulty | Length | Advance |
|---|---|---|---|
| 1 | easy | 20 min | top 30 |
| 2 | intermediate | 25 min | top 10 |
| 3 | final, hard | 30 min | winner |

Round lengths are typed by the organiser when starting each round; the values
in `sql/schema.sql` are only the defaults the prompt pre-fills.

## Announce before Round 1

> The best design wins. Speed does not earn points — the time limit is just the
> limit. If you tie at the cut-off, you all go through. In the final, shorter
> code breaks a tie.

And clearly: **do not rush to press Submit. It gains you nothing.** Without that
sentence participants assume speed matters and submit early.
