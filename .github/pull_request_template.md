## What and why

<!-- One or two sentences. Link the task IDs from specs/001-image-generation/tasks.md. -->

## How it was tested

<!-- Tests added or changed; manual checks, if any. -->

## Constitution check

- [ ] Money stays in integer nanodollars; no floats; rounding only in `CalculateCost`
- [ ] Status and money change only through `ApplyGenerationSnapshot` (after submission)
- [ ] Every new failure path has a test and a user-facing message
- [ ] Tests for database guarantees run on Postgres; no test calls the real Pixelforge API
- [ ] No secrets, keys or passwords in the diff
- [ ] Open decisions recorded in `DECISIONS.md`
