# Module Guide

This project organizes features into **modules** instead of dumping everything into one folder.

Each module = one feature. Self-contained. Easy to find stuff.

---

## Structure

Every module lives in `app/Modules/` and looks like this:

```
app/Modules/Projects/
├── Actions/          # Business logic
├── Data/             # DTOs / data classes
├── Http/
│   ├── Controllers/  # Handle request & return response
│   └── Requests/     # Validation
├── Models/           # Eloquent models
├── Services/         # External APIs & reusable logic
└── routes.php        # Module routes
```

---

## Create a Module

```bash
php artisan make:module Projects
```

That's it. Folders are created automatically.

---

## What Goes Where

| Layer | Job | Example |
|---|---|---|
| **Controller** | Receive request, return response | `ProjectController` |
| **Request** | Validate input | `StoreProjectRequest` |
| **Action** | Do one business task | `CreateProjectAction` |
| **Service** | External API or reusable logic | `OpenAiService` |
| **Model** | Talk to the database | `Project` |

**One Action = One job.** No "helpers" or "managers" that do everything.

---

## Request Flow

```
Route → Controller → Request → Action → Model/Service → Response
```

Example — user creates a project:

```
POST /projects
  → ProjectController@store
  → StoreProjectRequest (validates)
  → CreateProjectAction (saves project)
  → redirect to projects.index
```

---

## Quick Examples

**Controller** — keep it thin:
```php
public function store(StoreProjectRequest $request, CreateProjectAction $action)
{
    $action->handle($request->user(), $request->validated());
    return redirect()->route('projects.index');
}
```

**Action** — one job only:
```php
class CreateProjectAction
{
    public function handle(User $user, array $data): Project
    {
        return Project::create([
            'created_by' => $user->id,
            'name'       => $data['name'],
            'status'     => 'active',
        ]);
    }
}
```

**Namespaces** must match the folder path:
```php
namespace App\Modules\Projects\Http\Controllers;
namespace App\Modules\Projects\Actions;
namespace App\Modules\Projects\Models;
```

---

## Things That Stay Outside Modules

| Thing | Location |
|---|---|
| Migrations | `database/migrations/` |
| Vue pages | `resources/js/pages/` |
| User model | `app/Models/User.php` |

---

## When Something Breaks

```bash
# Route not found
php artisan route:list
php artisan optimize:clear

# Class not found
composer dump-autoload
php artisan optimize:clear
```

Also make sure `routes/web.php` has `load_module_routes();` at the bottom.

---

## New Feature Checklist

```
☐ Migration
☐ Model
☐ Request
☐ Action
☐ Controller
☐ routes.php
☐ Vue page
```
