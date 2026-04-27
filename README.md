# dp-members — Members module

Extracted from `daems-platform` Phase 1, 2026-04-28. Pattern proven by Insights pilot + Forum/Projects/Events extractions.

## Scope

- Member application submission (public form)
- Supporter application submission (public form)
- Public member profile (verified card via `/members/{uuid}`)
- Backstage applications list (pending + decided)
- Backstage application decide/dismiss workflow
- Backstage members list + status changes (suspend/reinstate/anonymise)
- Backstage member audit trail
- Application + member sparkline stats (KPI strips)
- Member activation service (called when an application is approved)

## Structure

- `module.json` — manifest read by core's `ModuleRegistry`
- `backend/src/` — PHP code under namespace `DaemsModule\Members\`
- `backend/bindings.php` — production DI bindings
- `backend/bindings.test.php` — test container bindings (InMemory fakes)
- `backend/routes.php` — public + backstage HTTP route registrations
- `backend/migrations/` — `members_NNN_*.sql` and `.php` migrations
- `frontend/public/` — `/members/{uuid}` public verification page + members section pages
- `frontend/backstage/` — `/backstage/members` + `/backstage/applications` admin pages
- `frontend/assets/` — KPI stats JS, public member page CSS

## Conventions

- PHPStan level 9 = 0 errors
- Tests use core's `KernelHarness`; per-module `bindings.test.php` swaps InMemory fakes
- Domain interfaces (`Daems\Domain\Membership\*`, `Daems\Domain\Member\*`, `Daems\Domain\Backstage\MemberDirectoryRepositoryInterface`, `Daems\Domain\Tenant\Tenant{Member,Supporter}CounterRepositoryInterface`) live in core; module binds them to module-owned implementations
- No `Co-Authored-By` in commits; identity is `Dev Team <dev@daems.fi>`

## Cross-module dependencies

- Core's `Daems\Application\Backstage\Notifications\ListNotificationsStats` injects `MemberApplicationRepositoryInterface` + `SupporterApplicationRepositoryInterface` + `AdminApplicationDismissalRepositoryInterface`. The bindings.php in this module satisfies those interfaces at runtime; core's notifications use case continues to work because Domain interfaces stay in core.
- The `daem-society` site has a special UUID route matcher at `public/index.php` that maps `/members/{uuid}` to this module's `frontend/public/profile.php`.
