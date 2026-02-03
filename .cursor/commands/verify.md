# verify

Provide **exact verification steps** to confirm the fix or feature in context. Include only what applies; use copy-pasteable commands and concrete values.

- **curl** — Full request(s): method, URL, headers (e.g. `Authorization`, `Content-Type`), and body. Show expected status and a key part of the response.
- **DB queries** — Exact `SELECT` (or relevant query) with table/columns; what to check (row count, field value). Note DB name or connection if not default.
- **Log patterns** — What to grep/search for (e.g. log line substring or level), where (file path or log stream), and what indicates success or failure.
- **UI steps** — Ordered steps: where to go (URL or nav path), what to click/fill, what should appear or change.

If the change spans layers, group steps by layer (API → DB → logs → UI). State any preconditions (e.g. user logged in, workspace selected).

This command will be available in chat with /verify
