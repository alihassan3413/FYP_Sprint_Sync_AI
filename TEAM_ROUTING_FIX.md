# Team-page routing fix

## Bug
`ChangeMemberRoleModal.vue` and `RemoveMemberDialog.vue` called
`workspaceRoute(name, member.id)` — a bare ID instead of a params object.
`workspaceRoute()` spreads its second argument (`{...params, workspace}`);
spreading a number produces `{}`, so the `user` route parameter was silently
dropped before reaching Ziggy.

## Fix
Both files now pass `{ user: member.id }` — matching the actual route
parameter name (`{user}`, bound to `User $user` in `TeamMemberController`,
see `app/Modules/Teams/Routes/web.php`).

- `resources/js/components/team/ChangeMemberRoleModal.vue` — role update PATCH
- `resources/js/components/team/RemoveMemberDialog.vue` — remove DELETE

No backend changes. `TeamMemberController` already worked correctly when hit
with the right params, as proven by `tests/Feature/Teams/TeamMemberTest.php`.

## Verified
- `php artisan test --compact` — 108 passed, 321 assertions.
- Traced both fixed calls against `workspaceRoute()` and the route
  definitions to confirm the generated URL now includes both `workspace`
  and `user`. No JS test runner exists in this project, so this is the only
  automated check available for the change.
