# Dynamic CRUD Manager and Table Builder

This project adds two Filament admin pages:

-   Table Builder: Create new database tables at runtime with a guided, safe wizard.
-   Dynamic CRUD Manager: Browse, search, edit, delete, and export any existing table without writing code.

Access is restricted to authenticated users with the admin role.

Quick links to notable classes:

-   [App\Providers\Filament\AdminPanelProvider::panel()](app/Providers/Filament/AdminPanelProvider.php:24)
-   [App\Models\User::canAccessPanel()](app/Models/User.php:54)
-   [App\Services\TableBuilderService::create()](app/Services/TableBuilderService.php:60)
-   [App\Filament\Pages\TableBuilder](app/Filament/Pages/TableBuilder.php:16)
-   [App\Filament\Pages\DynamicCrud](app/Filament/Pages/DynamicCrud.php:35)
-   [App\Models\DynamicModel](app/Models/DynamicModel.php:1)
-   [database/migrations/2025_08_11_000100_create_admin_audit_logs_table.php](database/migrations/2025_08_11_000100_create_admin_audit_logs_table.php)
-   [database/migrations/2025_08_11_000110_create_dynamic_tables_table.php](database/migrations/2025_08_11_000110_create_dynamic_tables_table.php)
-   [tests/Feature/TableBuilderServiceTest](tests/Feature/TableBuilderServiceTest.php:1)

## Access

-   Admin Panel URL: /admin
-   Only users with the admin role can access the panel and these pages. See [App\Models\User::canAccessPanel()](app/Models/User.php:54).
-   Each page performs a runtime gate check (403 on direct access) in:
    -   [App\Filament\Pages\TableBuilder::mount()](app/Filament/Pages/TableBuilder.php:29)
    -   [App\Filament\Pages\DynamicCrud::mount()](app/Filament/Pages/DynamicCrud.php:70)

## Pages

### Table Builder

-   Location: [App\Filament\Pages\TableBuilder](app/Filament/Pages/TableBuilder.php:16)
-   UI: Wizard steps (Table Info → Columns → Indexes & Constraints → Preview & Confirm)
-   Sticky action bar with Preview and Create Table buttons in [resources/views/filament/pages/table-builder.blade.php](resources/views/filament/pages/table-builder.blade.php)
-   Validations:
    -   Table name: snake_case, unique, not reserved.
    -   Column names: snake_case, unique per table, not reserved.
    -   Enum/set require options.
    -   Foreign keys must specify referenced table (and optional column and actions).
-   Conditional UI:
    -   string/char: length options.
    -   numeric (decimal/float/double): precision/scale.
    -   integer types: unsigned/auto-increment/primary toggles.
    -   boolean default uses a dedicated toggle.
    -   enum/set: options input appears.
    -   foreignId: reference table/column and on update/delete actions.
    -   soft deletes, timestamps, comments.
-   Preview:
    -   The Preview action generates a migration-like diff. See [App\Services\TableBuilderService::preview()](app/Services/TableBuilderService.php:19)
-   Create:
    -   Safe transactional create via [App\Services\TableBuilderService::create()](app/Services/TableBuilderService.php:60)
    -   Prevents name collisions and reserved names.
    -   Wraps schema changes in a DB transaction where supported.
    -   Persists table metadata in dynamic_tables if present.
    -   Logs an audit record in admin_audit_logs if present.

### Dynamic CRUD Manager

-   Location: [App\Filament\Pages\DynamicCrud](app/Filament/Pages/DynamicCrud.php:35)
-   Pick any existing table from a searchable dropdown (auto-includes tables created by Table Builder).
-   Runtime model binding uses [App\Models\DynamicModel](app/Models/DynamicModel.php:1)
-   Table auto-infers columns with type-aware renderers:
    -   boolean → icons, date/time → formatted TextColumn, enum/set → badges, json → truncated text with tooltip.
-   Features:
    -   Search, sort, pagination, filtering.
    -   Soft-deletes awareness:
        -   Defaults to hiding records with deleted_at, if present.
        -   Adds a Trashed ternary filter.
    -   Actions: view, edit, delete, bulk delete.
    -   CSV export action for the current table.
    -   Foreign key UX: columns ending with \_id render as a searchable select using a guessed label column (name/title/label/email).
-   Safety:
    -   Blocks deletion UI for critical system tables.
    -   Honors DB constraints; operations that would violate constraints are blocked by the DB.

## Supported Data Types

When creating columns via Table Builder:

-   String-like: string, char, text, mediumText, longText
-   Integer variants: integer, tinyInteger, smallInteger, mediumInteger, bigInteger
-   Numeric: decimal, float, double
-   Boolean: boolean
-   Date/time: date, time, datetime, timestamp
-   UUID/ULID: uuid, ulid
-   JSON: json
-   Enumerations: enum, set
-   Foreign key: foreignId (with reference table/column and on update/delete actions)

Per-type options are shown conditionally in the UI. Defaults and comments are supported. Index/unique/fulltext options are available when supported by the DB.

## Theme and UX

-   Telkomsel-inspired primary red applied to Filament panel in [App\Providers\Filament\AdminPanelProvider::panel()](app/Providers/Filament/AdminPanelProvider.php:31)
-   Modern UI: rounded corners, high-contrast colors, dark mode friendly, subtle depth and micro interactions.
-   Sticky action bars and progressive disclosure for advanced options.

## Audit Logging

-   Migration creates admin_audit_logs with user_id, action, context.
-   Table creation and CRUD operations log to admin_audit_logs if present:
    -   See [App\Services\TableBuilderService::create()](app/Services/TableBuilderService.php:60)
    -   See [App\Filament\Pages\DynamicCrud::audit()](app/Filament/Pages/DynamicCrud.php:530)

## Metadata

-   Optional metadata in dynamic_tables to persist UI definitions; live schema is always reflected to include tables created outside the builder.
-   Migration: [database/migrations/2025_08_11_000110_create_dynamic_tables_table.php](database/migrations/2025_08_11_000110_create_dynamic_tables_table.php)

## Tests

-   Feature tests for schema generation and validation in [tests/Feature/TableBuilderServiceTest](tests/Feature/TableBuilderServiceTest.php:1)
    -   Preview generation
    -   Creating a table with columns, defaults, timestamps, soft deletes
    -   Duplicate column names blocked
    -   Reserved table names blocked

Run tests:

-   php artisan test

## Limitations and Notes

-   Timezone-specific timestamp types depend on the DB platform. MySQL/MariaDB have limitations; use datetime/timestamp as applicable.
-   Table-level comments may not be applied on all platforms.
-   Fulltext index support depends on DB engine and version.
-   The builder currently focuses on creating new tables. Altering or dropping existing tables is intentionally out of scope to avoid destructive operations.

## Rollback Procedures

-   For a newly created table: php artisan migrate:rollback to drop admin_audit_logs and dynamic_tables tables (if they were just created). For user-created tables, dropping tables is manual.
-   If a table creation failed mid-operation, the transaction should rollback automatically. Confirm via DESCRIBE your_table.
-   To delete a mistakenly created table: drop it manually or via a dedicated safe migration.

## How to Use

1. Ensure you are logged in as an admin (run the seeder if needed):

-   [database/seeders/AdminUserSeeder](database/seeders/AdminUserSeeder.php:10)

2. Visit /admin. In the left navigation:

-   Table Builder: Guided creation of a new table.
-   Dynamic CRUD: Manage records in any existing table.

3. Create a table:

-   Fill Table Info, then add Columns with types and options.
-   Preview to see a migration-like blueprint.
-   Create Table to apply changes.

4. Manage a table:

-   Select a table from the dropdown.
-   Use view/edit/delete actions. Bulk delete and CSV export are available.

Security:

-   All schema changes use Laravel Schema and Eloquent APIs. Input is validated and sanitized.
-   Critical system tables are blocked from destructive actions.
