# How to run a worker

Written after a night where a 40-line change took an hour and three restarts.

## The cycle

1. **Decide the design yourself, before you open a worker.** If you're still thinking, don't dispatch.
2. **Write the brief: owner's words verbatim, your summary short.** Facts and constraints. Not reasoning.
3. **Name the files.** No wild goose hunt.
4. **Name the test classes to run.** Whitelist. Never "keep the suite green".
5. **Let it run. Do not re-brief.** If the design changes, stop it and start over — don't steer.
6. **Worker pushes. It does not merge.**
7. **You review.** Especially every assertion it changed.
8. **You merge.**

## The brief

**Put in:**
- The owner's actual words, quoted
- The files to touch
- The test classes to run
- Known traps (this repo: `$schema` in migrations, `*_rendered` byte-compare twins, SQLite decimals)
- What is out of scope, stated as a list

**Leave out:**
- Your reasoning about *why* the design is right
- Alternatives you considered
- Anything you're not sure about

> Rationale in a brief reads as instruction. The worker builds your reasoning instead of your
> instruction. Three wrong designs got built this way in one night.

## Never

- **Never re-brief a running worker.** Every correction restarts its exploration (~100k tokens). Three corrections = three restarts. Stop it and re-dispatch instead.
- **Never let a worker merge its own work.**
- **Never accept a green suite as proof** when the worker edited the assertions.
- **Never say "keep the suite green."** It will run the whole suite. ~1900 tests. Name the classes.
- **Never delegate something you already know how to do.** Exploration is the expensive part. If you know the shape, write it.

## Reviewing

The one question: **did it change any existing assertion?**

- Call site won't compile because a method is gone → fine, mechanical.
- Assertion value changed → **stop.** Either it's a deliberate behaviour change (make it say which), or it broke something and papered over it.

A worker that changes behaviour and adjusts the test in the same commit has removed the only thing that would have caught it.

## When NOT to use a worker

- Small change you already understand → just write it.
- Mechanical edit across many files → just write it.
- You're still deciding → decide first.

Workers are for work that needs **exploring**. Not for work that needs **typing**.

## Smell test

If you're writing the third message to a running worker — stop. The brief was wrong. Kill it and
start clean, or do it yourself.
