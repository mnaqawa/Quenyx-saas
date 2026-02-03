# rca

Produce a **Root Cause Analysis** using this structure. Fill each section from the current context (bug report, logs, code, or conversation). If information is missing, state what’s missing and what’s assumed.

1. **Symptoms** — What the user or system experiences (errors, behavior, impact).
2. **Facts** — Verified observations: logs, versions, config, repro steps, environment.
3. **Suspected root causes** — Plausible hypotheses, ordered by likelihood; briefly justify each.
4. **Confirmed root cause** — The actual cause (with evidence); or “Unconfirmed” and what’s needed to confirm.
5. **Fix plan** — Concrete steps: files/layers to change, order of fixes, any migration or rollout notes.
6. **Acceptance criteria** — How to verify the fix (tests, manual checks, observability).

Keep each section concise. Prefer bullet lists where appropriate.

This command will be available in chat with /rca
