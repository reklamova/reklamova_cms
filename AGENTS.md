# AGENTS.md - Reklamova CMS

## Fundamental Architecture Rule

Reklamova CMS is a self-hosted CMS installed separately for each client.
It is not a SaaS multi-tenant platform.

Each client installation has its own hosting, database, files, uploads,
configuration and domain.

## Core Modification Policy

The CMS core must not be modified for a single client.
The admin panel visual theme is part of the shared core and must stay identical
for all client installations.

Do not place client-specific logic inside:

- `/app/core`
- `/public/assets/core`
- `/app/migrations/core`
- shared framework/bootstrap files

Client-specific changes must be implemented only through:

- a client theme in `/app/themes/{client-theme}`
- a custom module in `/app/modules/custom/{module}`
- configuration in `/app/config`
- database-managed settings
- documented extension points

Client modules may add admin screens and navigation items through extension
points, but they must render inside the shared core admin shell. Do not create
client-specific admin layouts or duplicate admin CSS.

## Protected Paths

Core updates must never delete, overwrite or modify:

- `/app/config`
- `/app/themes`
- `/app/modules/custom`
- `/public/uploads`
- `/app/storage/backups`
- `/app/storage/logs`

## Update System Rules

Core updates are delivered as signed ZIP packages or, on capable hosting,
through Git/Composer.

Every update must:

1. verify package signature,
2. verify checksum,
3. check PHP and database requirements,
4. create a pre-update backup,
5. replace only allowed core paths,
6. run database migrations,
7. clear cache,
8. perform health check,
9. rollback automatically on failure.

## Module Rules

Modules must declare their metadata in `module.json`.

A module must not patch core files.
A module must use public CMS extension APIs, events, hooks or service contracts.

## Theme Rules

Client themes must not require modifications to core.
Themes should use the public theme API.

Core updates must preserve all client themes.

## Database Rules

Schema changes must be implemented as migrations.
Core migrations belong in `/app/migrations/core`.
Module migrations belong in the module directory.

Before update migrations run, the database must be backed up.

## Compatibility Rules

The CMS must remain compatible with shared hosting environments:

- PHP 8.3+
- MySQL or MariaDB
- no required SSH access
- ZIP-based updates
- optional Git/Composer mode only for capable hosting

## Central Maintenance Rule

Reklamova centrally maintains:

- CMS core repository,
- update server,
- signed packages,
- license/site key registry,
- central installation panel.

Reklamova does not centrally host all client websites in one multi-tenant app.
