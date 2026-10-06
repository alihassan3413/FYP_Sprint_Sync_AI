# Module Guide

This project organizes features into **modules** instead of dumping everything into one folder.

Each module = one feature. Self-contained. Easy to find stuff.

---

## Structure

Every module lives in `app/Modules/` and looks like this:

```
app/Modules/Projects/
├── Actions/              # Business logic, one job per class
├── Contracts/            # Interfaces other modules may depend on
├── Data/                 # DTOs, enums, value objects
├── Database/
│   ├── Factories/        # Model factories
│   └── Migrations/       # This module's migrations (loaded automatically)
├── Exceptions/
├── Http/
│   ├── Controllers/      # Handle request & return response
│   └── Requests/         # Validation + authorization
├── Models/               # Eloquent models
├── Policies/             # Registered via the provider's $policies
├── Providers/
│   └── ProjectsServiceProvider.php   # extends App\Support\Modules\ModuleServiceProvider
├── Routes/
│   └── web.php           # Module routes (loaded automatically)
└── Services/             # External APIs & reusable logic (private to the module)
```

The provider boots the module's policies, migrations and routes. It must be
listed in `bootstrap/providers.php`.

### Module boundaries

`tests/Unit/ModuleBoundaryTest.php` enforces that a module only imports another
module's `Contracts`, `Data`, `Models`, `Actions`, `Policies` and `Exceptions`.
`Http`, `Services` and `Support` are private to their module.

---

## Create a Module

```bash
php artisan make:module Projects --tenant
```

This creates the folders, the service provider, a `Routes/web.php` stub and
`tests/Feature/Projects/`. `--tenant` scopes the routes to a workspace
(`/{workspace:slug}/...`) through `App\Support\Routing\TenantRoute`.

Then register the provider in `bootstrap/providers.php`.

Create migrations inside the module:

```bash
php artisan make:migration create_projects_table --path=app/Modules/Projects/Database/Migrations
```

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
| Framework/core migrations (users, jobs, cache…) | `database/migrations/` |
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

Also make sure the module's provider is listed in `bootstrap/providers.php`:
module routes and migrations are only loaded through it.

---

## New Feature Checklist

```
☐ Migration (in Database/Migrations)
☐ Model + factory
☐ Policy (registered in the provider)
☐ Request
☐ Action
☐ Controller
☐ Routes/web.php
☐ Vue page
☐ Feature tests (tests/Feature/<Module>)
```
