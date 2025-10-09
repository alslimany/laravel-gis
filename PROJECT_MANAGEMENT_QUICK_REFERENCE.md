# Project Management - Quick Reference

## 🚀 Quick Actions

### Create Project
```
Navigation → Projects → New Project
- Name: Required
- Description: Optional
- Public: Optional checkbox
```

### View Projects
```
Navigation → Projects
Shows: Name, Layers, Collaborators, Owner, Status
```

### Share Project
```
Project → Share
- Toggle public/private
- Copy share link
- Copy embed code
```

### Add Collaborator
```
Project → Manage Collaborators
- Select user
- Choose role (Editor/Viewer)
- Click Add
```

### Add Comment
```
Project View → Comments section
- Type comment
- Click Add Comment
```

## 👥 User Roles

| Role | View | Edit | Delete | Share | Collaborate |
|------|------|------|--------|-------|-------------|
| Owner | ✅ | ✅ | ✅ | ✅ | ✅ |
| Editor | ✅ | ✅ | ❌ | ✅ | ✅ |
| Viewer | ✅ | ❌ | ❌ | ❌ | ✅* |

*Viewers can only comment if added as collaborators

## 🔗 Routes

```
GET    /projects                     List all projects
POST   /projects                     Create project
GET    /projects/{id}                View project
PUT    /projects/{id}                Update project
DELETE /projects/{id}                Delete project
GET    /projects/{id}/share          Share settings
GET    /projects/{id}/invite         Manage collaborators
GET    /projects/shared/{token}      Public view
```

## 💻 Code Snippets

### Create Project
```php
Project::create([
    'name' => 'My Project',
    'organization_id' => auth()->user()->organization_id,
    'user_id' => auth()->id(),
]);
```

### Add Collaborator
```php
$project->collaborators()->attach($userId, ['role' => 'editor']);
```

### Log Activity
```php
$project->logActivity('action_name', 'Description');
```

### Check Access
```php
$project->userCanAccess($user);
$project->hasUserRole($user, 'editor');
```

## 📊 Activity Types

- `created` - Project created
- `updated` - Project details updated
- `deleted` - Project deleted
- `collaborator_added` - New collaborator
- `collaborator_removed` - Removed collaborator
- `role_updated` - Role changed
- `comment_added` - Comment posted

## 🧪 Testing

```bash
# Run all project tests
php artisan test --filter=ProjectControllerTest

# Run specific test
php artisan test --filter=test_user_can_create_project
```

## 🔐 Permissions Required

| Action | Required Role |
|--------|--------------|
| View projects | Any organization member |
| Create project | Editor or Admin |
| Edit project | Editor or Admin (project owner) |
| Delete project | Admin (project owner) |
| Share project | Editor or Admin (project owner) |
| Add collaborators | Editor or Admin (project owner) |

## 🎯 Common Tasks

### Make Project Public
1. Open project
2. Click "Share"
3. Check "Make this project public"
4. Copy share link

### Add Team Member
1. Open project
2. Click "Manage Collaborators"
3. Select user from dropdown
4. Choose role
5. Click "Add"

### Change Collaborator Role
1. Open project
2. Click "Manage Collaborators"
3. Use role dropdown
4. Auto-saves

### Revoke Public Access
1. Open project
2. Click "Share"
3. Uncheck "Make this project public"

## 🐛 Quick Fixes

**Can't create project?**
- Need Editor or Admin role

**Can't see projects?**
- Must be in an organization

**Share link not working?**
- Project must be public
- Check URL is complete

**Can't add collaborator?**
- User must be in same organization
- Need Editor/Owner permissions

## 📝 Cheat Sheet

### Navigation
```
Dashboard → Projects → [Select Project]
├── View (Details, Layers, Activity)
├── Edit (Name, Description, Visibility)
├── Share (Link, Embed)
├── Collaborators (Add, Remove, Roles)
└── Comments (Add, Delete)
```

### Database Tables
```
projects              Main project data
project_user         Collaborator relationships
project_activities   Activity log
project_comments     Comments
```

### Key Models
```
Project              Main model
ProjectActivity      Activity tracking
ProjectComment       Comment system
```

### Key Controller
```
ProjectController    All CRUD + sharing + collaboration
```

## ⚡ Pro Tips

1. **Use descriptive names** - Makes searching easier
2. **Add descriptions** - Helps team understand purpose
3. **Review collaborators regularly** - Keep access list current
4. **Monitor activity logs** - Track changes
5. **Use comments** - Communicate with team
6. **Set appropriate roles** - Least privilege principle

---

**For full documentation:** See [PROJECT_MANAGEMENT.md](PROJECT_MANAGEMENT.md)
