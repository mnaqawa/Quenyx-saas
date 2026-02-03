# patch

Implement a **minimal safe patch** for the issue in context. Follow this workflow:

1. **File list** — List every file to be changed (path and one-line purpose). No broad refactors; only what’s needed to fix the issue.
2. **Implementation** — Apply the minimal code changes. Prefer targeted edits over large replacements; preserve existing behavior elsewhere.
3. **Tests** — Add or adjust tests that verify the fix (unit/integration/UI as appropriate). Include exact commands to run (e.g. `php artisan test --filter X`, `npm run test -- Y`).
4. **Rollback notes** — How to revert if needed: steps or commands (e.g. revert commit, re-run migration down, restore config). Note any data or state that might require manual rollback.

Before editing, confirm the file list with the user if the change touches multiple layers (e.g. backend + frontend + gateway).

This command will be available in chat with /patch
