# Project Management and Sharing - Complete Guide

## 📋 Overview

The Project Management system provides comprehensive tools for organizing, sharing, and collaborating on GIS projects within your Laravel GIS application. Projects serve as containers for organizing layers, managing team access, and controlling visibility.

## ✨ Key Features

### 1. Project Management
- **CRUD Operations**: Create, Read, Update, Delete projects
- **Organization-based Isolation**: Projects are scoped to organizations
- **Rich Metadata**: Names, descriptions, and spatial bounding boxes
- **Owner Tracking**: Each project has a designated owner

### 2. Sharing & Visibility
- **Public/Private Settings**: Control project visibility
- **Unique Share Tokens**: Auto-generated secure tokens for sharing
- **Embeddable Views**: Generate iframe code for external embedding
- **Public Viewer**: Standalone view for shared projects

### 3. Collaboration System
- **User Roles**: Owner, Editor, Viewer
- **Flexible Permissions**: Fine-grained access control
- **Team Invitations**: Add organization members as collaborators
- **Role Management**: Change user roles as needed

### 4. Activity Tracking
- **Automatic Logging**: All major actions are logged
- **User Attribution**: Track who did what
- **Audit Trail**: Complete history of project changes

### 5. Comments & Discussion
- **Project Comments**: Add comments to projects
- **Nested Replies**: Support for threaded discussions
- **User Attribution**: Comments linked to users
- **Access Control**: Only collaborators can comment

## 🚀 Getting Started

### Creating a Project

1. Navigate to **Projects** in the main menu
2. Click **New Project**
3. Fill in:
   - **Name** (required): Project identifier
   - **Description** (optional): Project details
   - **Make this project public** (optional): Enable public access
4. Click **Create Project**

### Viewing Projects

- **Project List**: View all projects in your organization
- **Project Dashboard**: See details, layers, collaborators, and activity
- **Recent Activity**: Track recent changes
- **Layers**: View all layers associated with the project

### Editing Projects

1. Click **Edit** on a project
2. Modify name, description, or visibility
3. Click **Update Project**

## 🔐 Roles & Permissions

### Owner
- Full control over the project
- Can delete the project
- Can manage all collaborators
- Can modify all settings

### Editor
- Can view and edit project details
- Can manage layers within the project
- Can add/remove collaborators
- Cannot delete the project

### Viewer
- Can view project details
- Can view associated layers
- Cannot modify anything
- Can add comments (if collaborator)

### Permission Matrix

| Action | Owner | Editor | Viewer |
|--------|-------|--------|--------|
| View Project | ✅ | ✅ | ✅ |
| Edit Project | ✅ | ✅ | ❌ |
| Delete Project | ✅ | ❌ | ❌ |
| Share Project | ✅ | ✅ | ❌ |
| Manage Collaborators | ✅ | ✅ | ❌ |
| Add Layers | ✅ | ✅ | ❌ |
| Add Comments | ✅ | ✅ | ✅* |

*Viewers can only comment if they are added as collaborators

## 🤝 Collaboration Workflow

### Adding Collaborators

1. Open the project
2. Click **Manage Collaborators**
3. Select a user from your organization
4. Choose a role (Editor or Viewer)
5. Click **Add**

### Changing Roles

1. Go to **Manage Collaborators**
2. Use the role dropdown next to a collaborator
3. Select new role (saves automatically)

### Removing Collaborators

1. Go to **Manage Collaborators**
2. Click **Remove** next to a collaborator
3. Confirm removal

## 🔗 Sharing Projects

### Making a Project Public

1. Open the project
2. Click **Share**
3. Toggle **Make this project public**
4. Project is now accessible via share link

### Share Link

When a project is public:
- A unique share URL is generated
- Anyone with the link can view the project
- No authentication required

Example: `https://your-domain.com/projects/shared/abc123xyz...`

### Embed Code

Generate iframe code to embed the project:

```html
<iframe src="https://your-domain.com/projects/shared/TOKEN" 
        width="100%" 
        height="600" 
        frameborder="0">
</iframe>
```

### Revoking Access

To revoke public access:
1. Go to **Share** settings
2. Uncheck **Make this project public**
3. Share link becomes invalid

## 💬 Comments System

### Adding Comments

1. View a project
2. Scroll to **Comments** section
3. Type your comment
4. Click **Add Comment**

### Deleting Comments

- Comment owners can delete their own comments
- Project owners can delete any comment

## 📊 Activity Logging

All major actions are automatically logged:

- Project created
- Project updated
- Project deleted
- Collaborator added
- Collaborator removed
- Role changed
- Comment added

View recent activity on the project dashboard.

## 🔍 API Endpoints

### Project Routes

```php
// Public routes
GET  /projects/shared/{token}      // View shared project

// Authenticated routes
GET    /projects                    // List projects
GET    /projects/create             // Show create form
POST   /projects                    // Create project
GET    /projects/{project}          // Show project
GET    /projects/{project}/edit     // Show edit form
PUT    /projects/{project}          // Update project
DELETE /projects/{project}          // Delete project

// Sharing
GET  /projects/{project}/share      // Share settings

// Collaboration
GET    /projects/{project}/invite          // Invitation form
POST   /projects/{project}/invite          // Add collaborator
DELETE /projects/{project}/collaborators/{user}  // Remove
PUT    /projects/{project}/collaborators/{user}/role  // Update role

// Comments
POST   /projects/{project}/comments        // Add comment
DELETE /projects/{project}/comments/{comment}  // Delete
```

## 🗄️ Database Schema

### Projects Table

```sql
- id
- name
- description
- organization_id (FK)
- user_id (FK)
- bounding_box (GEOMETRY)
- is_public
- share_token
- timestamps
```

### project_user Pivot Table

```sql
- id
- project_id (FK)
- user_id (FK)
- role (owner, editor, viewer)
- timestamps
```

### project_activities Table

```sql
- id
- project_id (FK)
- user_id (FK)
- action
- description
- properties (JSON)
- timestamps
```

### project_comments Table

```sql
- id
- project_id (FK)
- user_id (FK)
- content
- parent_id (FK, self-reference)
- timestamps
```

## 🧪 Testing

Comprehensive test suite included:

```bash
php artisan test --filter=ProjectControllerTest
```

**26 tests covering:**
- CRUD operations
- Authorization checks
- Sharing functionality
- Collaboration features
- Comment system
- Activity logging
- Organization isolation

## 💡 Best Practices

### 1. Organization Structure
- Use projects to group related layers
- Keep project names descriptive
- Add detailed descriptions

### 2. Collaboration
- Use Editor role for trusted team members
- Use Viewer role for stakeholders
- Regularly review collaborator list

### 3. Sharing
- Only make projects public when necessary
- Include clear descriptions for public projects
- Monitor activity logs for public projects

### 4. Security
- Projects are isolated by organization
- Share tokens are cryptographically secure
- Collaborators must be in same organization
- Activity is logged for accountability

## 🔧 Code Examples

### Creating a Project Programmatically

```php
use App\Models\Project;

$project = Project::create([
    'name' => 'City Planning 2024',
    'description' => 'Urban development project',
    'organization_id' => auth()->user()->organization_id,
    'user_id' => auth()->id(),
    'is_public' => false,
]);

// Log activity
$project->logActivity('created', 'Project initialized');
```

### Adding Collaborators

```php
$project->collaborators()->attach($userId, [
    'role' => 'editor'
]);

$project->logActivity('collaborator_added', "Added user as editor");
```

### Checking Access

```php
if ($project->userCanAccess($user)) {
    // User has access
}

if ($project->hasUserRole($user, 'editor')) {
    // User is an editor
}
```

### Adding Comments

```php
$project->comments()->create([
    'user_id' => auth()->id(),
    'content' => 'Great progress on this project!',
]);

$project->logActivity('comment_added', 'User added a comment');
```

## 🐛 Troubleshooting

### Can't See Projects
- Verify you're assigned to an organization
- Check you have appropriate role permissions

### Can't Add Collaborators
- Ensure user is in same organization
- Verify you have Editor or Owner role

### Share Link Not Working
- Confirm project is set to public
- Check share token is correct
- Verify URL is complete

### Comments Not Showing
- Ensure you're a collaborator or organization member
- Refresh the page

## 📚 Related Documentation

- [Layer Management](LAYER_MANAGEMENT.md)
- [Map Builder](MAP_BUILDER_DOCUMENTATION.md)
- [Data Import](DATA_IMPORT.md)
- [GeoServer Integration](GEOSERVER_INTEGRATION.md)

## 🎯 Future Enhancements

Potential improvements for future versions:

- Project templates
- Bulk operations
- Export/import project configurations
- Advanced permissions (custom roles)
- Project duplication
- Project archiving
- Email notifications for activity
- Comment mentions (@user)
- Project tags/categories
- Advanced search and filtering

## 📞 Support

For issues or questions:
1. Check this documentation
2. Review test cases in `tests/Feature/ProjectControllerTest.php`
3. Examine the code in `app/Http/Controllers/ProjectController.php`
4. Contact your system administrator

---

**Version:** 1.0  
**Last Updated:** October 2024  
**Status:** Production Ready ✅
