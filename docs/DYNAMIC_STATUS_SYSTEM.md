# Dynamic Status System Documentation

## Overview

The system now supports **organization-specific statuses** that can be customized by each organization. Organizations can:
- Use default statuses (To Do, In Progress, Done)
- Rename default statuses (organization-specific)
- Add custom statuses
- Set custom colors for each status
- Control display order

## Database Structure

### Statuses Table

```sql
CREATE TABLE statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    display_order INT DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0,
    color VARCHAR(50) DEFAULT '#6c757d',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    UNIQUE KEY unique_org_status (organization_id, name)
)
```

**Key Fields:**
- `organization_id`: NULL for global defaults, specific ID for organization-specific statuses
- `name`: Status name (e.g., "To Do", "In Progress", "Done", "On Hold")
- `display_order`: Controls the order statuses appear (lower numbers first)
- `is_default`: 1 for default statuses (cannot be deleted, only renamed)
- `color`: Hex color code for status badge/display

## Setup

### 1. Run Migration Script

Run the setup script to create the table and populate default statuses:

```bash
php setup/create_statuses_table.php
```

This will:
- Create the `statuses` table
- Add default statuses (To Do, In Progress, Done) for all existing organizations
- Create global default statuses for Super Admin

### 2. Access Status Management

Organization Admins can access status management:
- **Desktop**: Profile menu → "Manage Statuses"
- **Mobile**: Profile menu → "Manage Statuses"

## Features

### Default Statuses

Every organization starts with three default statuses:
1. **To Do** (Yellow: #ffc107)
2. **In Progress** (Blue: #17a2b8)
3. **Done** (Gray: #6c757d)

These can be renamed but not deleted.

### Custom Statuses

Organizations can add unlimited custom statuses:
- Examples: "On Hold", "Review", "Blocked", "Testing"
- Each can have a custom color
- Can be reordered using display_order
- Can be deleted if no tasks are using them

### Organization-Specific Customization

- **Renaming**: Organizations can rename "To Do" to "Pending", "In Progress" to "Active", etc.
- **Isolation**: Changes only affect that organization
- **Colors**: Each status can have a custom color for visual distinction

## Usage

### Managing Statuses

1. Go to **Profile Menu → Manage Statuses**
2. **Add Status**: Enter name, choose color, set display order
3. **Edit Status**: Click edit icon to rename, change color, or reorder
4. **Delete Status**: Click delete icon (only for non-default, unused statuses)

### Status Display

Statuses appear throughout the system:
- **Dashboard**: Status badges with counts
- **Tasks Page**: Status filter badges and dropdown in grid
- **Task View**: Status dropdown in metadata bar
- **Charts**: Status distribution charts use dynamic statuses

## Helper Functions

### `getStatuses($organization_id = null)`

Returns all statuses for an organization, ordered by display_order.

```php
$statuses = getStatuses($organization_id);
// Returns: [['id' => 1, 'name' => 'To Do', 'color' => '#ffc107', ...], ...]
```

### `getStatusByName($status_name, $organization_id = null)`

Gets a specific status by name for an organization.

```php
$status = getStatusByName('To Do', $organization_id);
// Returns: ['id' => 1, 'name' => 'To Do', 'color' => '#ffc107', ...]
```

### `buildStatusCountSQL($statuses, $prefix = '')`

Builds dynamic SQL for status counts in queries.

```php
$status_count_sql = buildStatusCountSQL($statuses, 't.');
// Returns: "SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as to_do_count, ..."
```

## Integration Points

### Tasks Table

The `tasks.status` column stores the status **name** (not ID), allowing:
- Easy migration (no foreign key constraints)
- Flexibility for custom statuses
- Backward compatibility

### Status Validation

All status updates validate against organization's statuses:
- Prevents invalid status values
- Ensures data integrity
- Provides clear error messages

## Migration Notes

### Existing Tasks

Existing tasks with status "Done" or "Closed" will continue to work:
- The system normalizes display names
- Database stores actual status name
- Migration script populates default statuses

### Backward Compatibility

The system maintains backward compatibility:
- Old status values still work
- `normalizeStatusForDisplay()` handles legacy statuses
- Gradual migration supported

## Best Practices

1. **Default Statuses**: Keep default statuses (To Do, In Progress, Done) as they're standard
2. **Naming**: Use clear, concise status names
3. **Colors**: Choose distinct colors for easy visual identification
4. **Order**: Set display_order to control logical flow (e.g., 1=To Do, 2=In Progress, 3=Done)
5. **Deletion**: Only delete statuses that aren't in use

## Example Workflow

1. Organization Admin logs in
2. Goes to "Manage Statuses"
3. Renames "To Do" to "Backlog" (organization-specific)
4. Adds custom status "On Hold" with color #FFA500
5. All tasks, dashboard, and filters now show "Backlog", "In Progress", "Done", "On Hold"
6. Other organizations still see "To Do", "In Progress", "Done"

## Files Modified

- `setup/create_statuses_table.php` - Database setup script
- `manage_statuses.php` - Status management page
- `config/config.php` - Helper functions
- `tasks.php` - Dynamic status dropdowns and filters
- `dashboard.php` - Dynamic status badges and charts
- `task_view.php` - Dynamic status dropdown
- `includes/header.php` - Added "Manage Statuses" menu item
