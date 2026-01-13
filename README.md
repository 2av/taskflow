# Simple Jira-like Task & Issue Management System

A web-based internal Task & Issue Management System built with PHP, MySQL, HTML, CSS, and jQuery.

## Features

- **User Management**: Admin can create, edit, and manage users with role-based access
- **Project Management**: Create and manage projects with team members
- **Task/Issue Tracking**: Create tasks with types (Task, Bug, Improvement), priorities, and statuses
- **Workflow Management**: Simple workflow (To Do → In Progress → Done)
- **Comments & Activity Logs**: Track all changes and discussions on tasks
- **Dashboard**: View statistics and recent tasks based on user role
- **Search & Filters**: Search tasks and filter by project, status, priority, and assignee
- **Role-Based Access Control**: Admin, Project Manager, and Team Member roles

## Requirements

- PHP 7.0 or higher
- MySQL 5.7 or higher
- Apache web server (XAMPP recommended)
- Modern web browser

## Installation

1. **Extract the project** to your web server directory (e.g., `C:\xampp\htdocs\jira`)

2. **Configure Database** (if needed):
   - Open `config/database.php`
   - Update database credentials if different from default:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_USER', 'root');
     define('DB_PASS', '');
     define('DB_NAME', 'jira_system');
     ```

3. **Setup Database**:
   - Start your MySQL server (via XAMPP or your MySQL service)
   - Open your browser and navigate to: `http://localhost/jira/setup/database_setup.php`
   - Click the "Create All Tables Automatically" button
   - Wait for the success message

4. **Login**:
   - Navigate to: `http://localhost/jira/`
   - Default admin credentials:
     - Username: `admin`
     - Password: `admin123`

## Project Structure

```
jira/
├── assets/
│   ├── css/
│   │   └── style.css          # Main stylesheet
│   └── js/
│       └── main.js            # JavaScript functions
├── config/
│   ├── config.php             # Configuration and helper functions
│   └── database.php           # Database connection
├── includes/
│   ├── header.php             # Header and navigation
│   └── footer.php             # Footer
├── setup/
│   └── database_setup.php     # Database setup page
├── dashboard.php              # Main dashboard
├── index.php                  # Login page
├── logout.php                 # Logout handler
├── users.php                  # User management (Admin only)
├── projects.php               # Project management
├── tasks.php                  # Task management
└── task_view.php              # Task details, comments, activity logs
```

## User Roles

### Admin
- Full system access
- Manage users and roles
- Create and manage all projects
- View all tasks and reports

### Project Manager
- Manage assigned projects
- Create and assign tasks
- Monitor project progress
- View tasks in assigned projects

### Team Member
- View assigned projects
- Work on assigned tasks
- Update task status and add comments
- View own task dashboard

## Database Tables

- `roles` - User roles (Admin, Project Manager, Team Member)
- `users` - User accounts
- `projects` - Projects
- `project_users` - Project-team member relationships
- `tasks` - Tasks/Issues
- `task_comments` - Comments on tasks
- `activity_logs` - Activity history

## Security Features

- Password hashing using PHP `password_hash()`
- Prepared statements to prevent SQL injection
- Role-based access control
- Session management
- Input validation and sanitization

## Default Workflow

Tasks follow a simple workflow:
- **To Do** → **In Progress** → **Done**

Status changes are automatically logged in the activity log.

## Usage Tips

1. **Creating Projects**: Only Admin and Project Managers can create projects
2. **Assigning Tasks**: Tasks can be assigned to team members when created or edited
3. **Task IDs**: Automatically generated as `PROJECTCODE-NUMBER` (e.g., `WEB-1`)
4. **Comments**: Any logged-in user can add comments to tasks they have access to
5. **Activity Logs**: All status, assignee, and priority changes are automatically logged

## Troubleshooting

- **Database connection error**: Check MySQL is running and credentials in `config/database.php`
- **Tables not created**: Make sure MySQL user has CREATE DATABASE and CREATE TABLE permissions
- **Login not working**: Verify database setup completed successfully and default admin user exists
- **Permission denied**: Check user role and project assignments

## Future Enhancements

- Email notifications
- File attachments on tasks
- Advanced reports and analytics
- REST API for mobile app
- Custom workflows
- Time tracking

## License

This project is for internal organizational use.

## Support

For issues or questions, please contact your system administrator.
