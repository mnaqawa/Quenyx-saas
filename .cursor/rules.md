Stack:
- Frontend: React + TypeScript (Vite)
- Backend: Laravel API-only
- DB: MySQL

Rules:
- No auth/user management unless explicitly requested
- Controllers thin, logic in Services, DB in Repositories
- Strict TypeScript, no logic in React components
- Every endpoint validated and returns consistent JSON errors
- Add basic tests for each feature
- Small PRs only (one issue = one slice)
