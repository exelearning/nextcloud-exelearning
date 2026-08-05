---
name: verify
description: Run the full verification pipeline locally — typecheck, lint, JS and PHP tests, build, and the architecture record check. Use after making changes to confirm they are ready.
---

Run these in sequence. **Stop and report the first failure** — do not continue
and do not summarise a run you did not see finish.

```bash
composer install
npm install
npm run typecheck
npm run lint
npm test
make architecture-check
make architecture-test
npm run build
vendor/bin/phpunit --configuration tests/phpunit.xml
git diff --check
```

`make lint` already runs `architecture-check`; the explicit line above is for
when you only want that one check.

Report:

- which commands passed, with their real output;
- any failure, with the relevant error text, not a paraphrase;
- explicitly, any command you could **not** run because a dependency is missing
  in the environment.

Never claim "tests pass" without having seen them pass. A command that was
skipped is not a command that succeeded.
