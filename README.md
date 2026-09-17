# Find IT — PHP + SQLite

A production-style campus Lost-and-Found Management System built for local development first, then Railway deployment.

## Stack
- PHP 8+
- SQLite
- HTML/CSS/JavaScript
- PDO
- Apache Docker image for Railway

## Local setup

Requirements:
- PHP 8+
- PDO SQLite extension

From this folder:

```bash
php -S localhost:8000
```

Open:

`http://localhost:8000`

The database is created automatically at:

`storage/findit.sqlite`

Uploads are stored at:

`uploads/`

## Local test accounts

Admin:
- Email: `admin@findit.local`
- Password: `admin123`

Student:
- Email: `student@findit.local`
- Password: `student123`

These are development seed accounts. The site does NOT automatically log either account in.

## Main flows

Public:
- Home
- Browse Lost Items
- Browse Found Items
- Search
- Item Details

Authentication required:
- Report Lost
- Report Found
- Claim Item
- My Reports
- My Claims
- Notifications
- Profile
- Settings

Admin:
- Report verification
- Claim management
- Item lifecycle management
- User management
- Reports & statistics
- Guidance notifications
- Audit logs

## Status model

Report type:
- Lost
- Found

Report status:
- Pending Review
- Approved
- Rejected

Claim status:
- Pending
- Approved
- Rejected

Item status:
- Open
- Matched
- Claim Pending
- Returned
- Closed

## Railway

Set environment variables:

`SQLITE_DB_PATH=/data/findit.sqlite`
`UPLOAD_PATH=/data/uploads`

Attach a persistent Railway volume mounted at `/data` so SQLite records and uploaded photos survive service restarts/redeployments.

The Dockerfile uses the Railway `PORT` environment variable.

For a production deployment, change seed credentials and review the local password-reset flow before exposing it publicly.
