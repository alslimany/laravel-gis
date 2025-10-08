# Authentication and Authorization Guide

This guide explains the authentication and role-based authorization system implemented in the Laravel GIS application.

## Overview

The application implements a comprehensive authentication and authorization system with:
- User registration and login
- Three role types: Admin, Editor, and Viewer
- Organization-based data isolation
- Policy-based authorization for resources

## Features

### 1. Authentication

Users can register and login using Laravel UI authentication scaffolding:
- **Registration**: `/register` - Create a new user account
- **Login**: `/login` - Authenticate existing users
- **Logout**: `/logout` - End user session
- **Password Reset**: `/password/reset` - Reset forgotten passwords

### 2. Roles

Three roles are available with different permission levels:

#### Admin
- Full access to all features
- Can manage users within their organization
- Can create, edit, and delete projects
- Can modify organization settings

#### Editor
- Can create and edit projects
- Can view organization data
- Cannot delete projects or manage users

#### Viewer
- Read-only access to organization data
- Can view projects and organization information
- Cannot create, edit, or delete anything

### 3. Organization-Based Access Control

- Users belong to one organization
- Projects belong to organizations
- Users can only access data within their organization
- Admins can only manage users in their organization

## Models and Relationships

### User Model

```php
$user->organization;        // Get the user's organization
$user->roles;               // Get all roles assigned to the user
$user->hasRole('admin');    // Check if user has a specific role
$user->isAdmin();          // Check if user is an admin
$user->hasAnyRole(['admin', 'editor']); // Check for multiple roles
```

### Organization Model

```php
$org->user;          // Get the organization owner
$org->members;       // Get all users in the organization
$org->projects;      // Get all projects in the organization
```

### Project Model

```php
$project->organization;  // Get the project's organization
```

## Middleware

### Authentication Middleware (`auth`)
Ensures user is authenticated before accessing protected routes.

```php
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth');
```

### Role Middleware (`role`)
Restricts access to users with specific roles.

```php
Route::resource('users', UserController::class)
    ->middleware('role:admin');
```

### Organization Middleware (`organization`)
Ensures user belongs to an organization.

```php
Route::get('/organization/settings', [OrganizationController::class, 'settings'])
    ->middleware(['auth', 'organization']);
```

## Policies

### ProjectPolicy
Controls access to project operations:
- `viewAny`: User must belong to an organization
- `view`: User must be in the same organization as the project
- `create`: User must be an Admin or Editor
- `update`: User must be an Admin or Editor in the same organization
- `delete`: User must be an Admin in the same organization

### OrganizationPolicy
Controls access to organization operations:
- `view`: User must belong to the organization
- `update`: User must be an Admin in the organization
- `delete`: User must be an Admin in the organization

### UserPolicy
Controls access to user management:
- `viewAny`: User must be an Admin
- `view`: Admin can view users in their organization
- `update`: Admin can update users in their organization
- `delete`: Admin can delete users in their organization (except themselves)

## Routes

### Public Routes
- `GET /` - Welcome page
- `GET /login` - Login form
- `POST /login` - Process login
- `GET /register` - Registration form
- `POST /register` - Process registration

### Protected Routes (Authenticated Users)
- `GET /dashboard` - User dashboard
- `GET /organization/settings` - Organization settings (requires organization membership)
- `PUT /organization` - Update organization (requires admin role)

### Admin Routes
- `GET /users` - List users in organization
- `GET /users/{user}/edit` - Edit user form
- `PUT /users/{user}` - Update user
- `DELETE /users/{user}` - Delete user

## Views

### Dashboard (`resources/views/dashboard.blade.php`)
Displays:
- User's organization information
- User's assigned roles
- Recent projects in the organization
- Links to organization settings (for admins)
- Links to user management (for admins)

### User Management (`resources/views/users/`)
- `index.blade.php` - List all users in organization
- `edit.blade.php` - Edit user details and roles

### Organization Settings (`resources/views/organization/settings.blade.php`)
- Update organization name and description
- View member and project counts

## Database Schema

### Roles Table
```sql
- id
- name (unique: admin, editor, viewer)
- description
- timestamps
```

### Role_User Pivot Table
```sql
- id
- role_id (foreign key to roles)
- user_id (foreign key to users)
- timestamps
- unique(role_id, user_id)
```

### Users Table (additional fields)
```sql
- organization_id (nullable, foreign key to organizations)
```

## Seeding Data

Run the database seeder to create default roles and test data:

```bash
php artisan db:seed
```

This creates:
- Three default roles (admin, editor, viewer)
- A test user with admin role
- Test organization with projects
- Additional test users with different roles

## Testing

The authentication and authorization system includes comprehensive tests:

### Authentication Tests (7 tests)
- User can register
- User can login
- User can logout
- Authenticated user can access dashboard
- Guest cannot access dashboard
- User with role can be identified
- User can have multiple roles

### Authorization Tests (12 tests)
- Only admin can access user management
- Admin can edit users in same organization
- Admin cannot edit users in different organization
- Editor can create projects
- Viewer cannot create projects
- User can view project in same organization
- User cannot view project in different organization
- Admin can delete projects
- Editor cannot delete projects
- User without organization cannot access organization settings
- Admin can update organization settings
- Viewer cannot update organization settings

### Organization Data Isolation Tests (8 tests)
- Users can only see projects in their organization
- Organization scope filters projects correctly
- Organization members count is correct
- Dashboard shows only organization projects
- Admin can only manage users in same organization
- User without organization cannot create projects
- Organization settings page shows correct organization
- User can belong to only one organization

Run tests:
```bash
# Run all tests
php artisan test

# Run only authentication tests
php artisan test --filter=AuthenticationTest

# Run only authorization tests
php artisan test --filter=AuthorizationTest

# Run only organization isolation tests
php artisan test --filter=OrganizationDataIsolationTest
```

## Usage Examples

### Checking Permissions in Controllers

```php
public function update(Request $request, Project $project)
{
    // Check if user can update the project
    $this->authorize('update', $project);
    
    // Update logic here...
}
```

### Checking Permissions in Views

```blade
@can('update', $organization)
    <a href="{{ route('organization.settings') }}">Settings</a>
@endcan

@if(auth()->user()->isAdmin())
    <a href="{{ route('users.index') }}">Manage Users</a>
@endif
```

### Query Scoping

```php
// Get projects for a specific organization
$projects = Project::forOrganization($organizationId)->get();

// Get organizations for a specific user
$organizations = Organization::forUser($userId)->get();
```

## Security Considerations

1. **Organization Isolation**: All authorization checks verify organization membership
2. **Role-Based Access**: Different permission levels for different user types
3. **Policy Enforcement**: Policies automatically check both role and organization
4. **Middleware Protection**: Routes are protected at multiple levels
5. **Self-Protection**: Users cannot delete themselves
6. **Cross-Organization Protection**: Users cannot access data from other organizations

## Customization

### Adding New Roles

1. Create a new role in the database:
```php
Role::create([
    'name' => 'manager',
    'description' => 'Project manager with elevated permissions'
]);
```

2. Update policies to include the new role:
```php
public function update(User $user, Project $project): bool
{
    return $user->organization_id === $project->organization_id &&
           $user->hasAnyRole(['admin', 'editor', 'manager']);
}
```

### Adding New Policies

1. Generate a policy:
```bash
php artisan make:policy TaskPolicy --model=Task
```

2. Define authorization methods:
```php
public function view(User $user, Task $task): bool
{
    return $user->organization_id === $task->project->organization_id;
}
```

3. Register the policy in `AuthServiceProvider` if needed (Laravel auto-discovers policies).
