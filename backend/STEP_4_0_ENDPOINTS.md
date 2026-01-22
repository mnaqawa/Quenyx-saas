# Step 4.0 Backend API Endpoints

## Authentication
All endpoints require `Authorization: Bearer {token}` header (Sanctum).

## Profile/Identity

### GET /api/me
Get current user profile.

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

### PUT /api/me
Update user name (email is read-only).

**Request Body:**
```json
{
  "name": "John Smith"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Smith",
    "email": "john@example.com"
  }
}
```

## Project Memberships (RBAC)

### GET /api/projects/{project}/memberships
List project memberships and invites. Requires owner or admin role.

**Response:**
```json
{
  "success": true,
  "data": {
    "memberships": [
      {
        "id": null,
        "user_id": 1,
        "user": {
          "id": 1,
          "name": "Owner Name",
          "email": "owner@example.com"
        },
        "role": "owner",
        "created_at": "2026-01-15T10:00:00.000000Z"
      },
      {
        "id": 2,
        "user_id": 3,
        "user": {
          "id": 3,
          "name": "Member Name",
          "email": "member@example.com"
        },
        "role": "admin",
        "created_at": "2026-01-15T11:00:00.000000Z"
      }
    ],
    "invites": [
      {
        "id": 1,
        "email": "invitee@example.com",
        "role": "member",
        "status": "pending",
        "invited_by": {
          "id": 1,
          "name": "Owner Name"
        },
        "created_at": "2026-01-15T12:00:00.000000Z",
        "expires_at": "2026-01-22T12:00:00.000000Z"
      }
    ]
  }
}
```

### POST /api/projects/{project}/memberships
Add member directly (user must exist). Requires owner or admin role.

**Request Body:**
```json
{
  "email": "user@example.com",
  "role": "member"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 3,
    "user_id": 5,
    "user": {
      "id": 5,
      "name": "User Name",
      "email": "user@example.com"
    },
    "role": "member",
    "created_at": "2026-01-15T13:00:00.000000Z"
  }
}
```

### POST /api/projects/{project}/memberships/invite
Create invite for user (may not exist yet). Requires owner or admin role.

**Request Body:**
```json
{
  "email": "newuser@example.com",
  "role": "viewer"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 2,
    "email": "newuser@example.com",
    "role": "viewer",
    "status": "pending",
    "invited_by": {
      "id": 1,
      "name": "Owner Name"
    },
    "created_at": "2026-01-15T14:00:00.000000Z",
    "expires_at": "2026-01-22T14:00:00.000000Z"
  }
}
```

### PUT /api/projects/{project}/memberships/{membership}
Update membership role. Requires owner or admin role. Only owner can promote to owner.

**Request Body:**
```json
{
  "role": "admin"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 3,
    "user_id": 5,
    "user": {
      "id": 5,
      "name": "User Name",
      "email": "user@example.com"
    },
    "role": "admin",
    "created_at": "2026-01-15T13:00:00.000000Z"
  }
}
```

### DELETE /api/projects/{project}/memberships/{membership}
Remove membership. Requires owner or admin role. Cannot remove last owner.

**Response:**
```json
{
  "success": true
}
```

## Invite Acceptance

### POST /api/invites/{token}/accept
Accept a project invite by token. Requires authentication.

**Rules:**
- Must be authenticated
- Invite must exist (404 if token invalid)
- Invite status must be 'pending' (409 if not pending)
- Invite email must match authenticated user email (case-insensitive, 403 if mismatch)
- User must not already have membership in the project (409 if exists, role is NOT changed)

**Response:**
```json
{
  "success": true,
  "data": {
    "membership": {
      "id": 3,
      "user_id": 5,
      "user": {
        "id": 5,
        "name": "User Name",
        "email": "user@example.com"
      },
      "role": "member",
      "created_at": "2026-01-15T15:00:00.000000Z"
    },
    "project": {
      "id": 1,
      "name": "Project Name",
      "status": "active"
    }
  }
}
```

**Error Responses:**
- `404`: Invite not found
- `403`: Email mismatch
- `409`: Invite not pending OR user already has membership

## My Workspaces

### GET /api/projects
List all projects the authenticated user is a member of (as owner or via membership).

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "project": {
        "id": 1,
        "name": "My Project",
        "status": "active",
        "created_at": "2026-01-15T10:00:00.000000Z",
        "updated_at": "2026-01-15T10:00:00.000000Z"
      },
      "my_role": "owner"
    },
    {
      "project": {
        "id": 2,
        "name": "Another Project",
        "status": "active",
        "created_at": "2026-01-15T11:00:00.000000Z",
        "updated_at": "2026-01-15T11:00:00.000000Z"
      },
      "my_role": "admin"
    }
  ]
}
```

**Notes:**
- Returns projects where user is owner (my_role: "owner")
- Returns projects where user has membership (my_role from membership.role)
- Deduplicates if user is both owner and has membership record
- Sorted by updated_at descending

## Authorization Rules

- **Owner**: Can view, invite, add, update, and remove members. Can promote to owner.
- **Admin**: Can view, invite, add, update, and remove members. Cannot change/remove owner. Cannot promote to owner.
- **Member/Viewer**: Cannot manage memberships.

## Removed Endpoints (Out of Scope)

- ❌ POST /api/plans
- ❌ PUT /api/plans/{plan}
- ❌ DELETE /api/plans/{plan}
- ❌ PUT /api/profile/password
- ❌ GET /api/profile
- ❌ PUT /api/profile
- ❌ GET /api/profile/stats

## Read-Only Endpoints (Kept)

- ✅ GET /api/plans (read-only catalog)
