# Development Commands

This document lists development-only artisan commands for testing and setup.

## Reset User Workspaces

**Command:** `php artisan quenyx:reset-workspaces {email} {--count=4}`

**Description:** DEV-ONLY command to reset a user's workspaces by deleting all existing projects and creating sample ones.

**Usage:**
```bash
# Reset workspaces for a user (creates 4 default workspaces)
php artisan quenyx:reset-workspaces user@example.com

# Create only 2 sample workspaces
php artisan quenyx:reset-workspaces user@example.com --count=2
```

**What it does:**
1. Finds user by email (required argument)
2. Deletes all projects where user is owner OR member
   - Also deletes related `project_memberships` and `project_invites`
   - Other related data (module overrides, subscriptions, audit logs) are handled by cascade deletes
3. Creates N sample workspaces (default: 4):
   - "Production Env"
   - "Staging Env"
   - "Product X"
   - "Product Y"
4. Creates owner membership for the user in each workspace

**Safety:**
- Command refuses to run if `APP_ENV=production`
- Requires confirmation before executing
- Prints summary of deleted and created projects

**Sample Output:**
```
⚠️  WARNING: This will DELETE all projects (workspaces) where this user is a member or owner.
   This includes related memberships and invites.

Do you want to proceed for user: John Doe (user@example.com)? (yes/no) [no]:
> yes

Found 3 project(s) to delete.

✓ Successfully reset workspaces for user: John Doe (user@example.com)
  Deleted: 3 project(s)
  Created: 4 project(s)

Created workspaces:
  - Production Env (ID: 5)
  - Staging Env (ID: 6)
  - Product X (ID: 7)
  - Product Y (ID: 8)
```

**Constraints:**
- DEV-ONLY: Cannot run in production environment
- Does not touch billing, plans, subscriptions, entitlements
- Roles remain project-scoped only
