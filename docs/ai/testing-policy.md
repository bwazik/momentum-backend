# Testing Policy — Momentum Backend

> Read when adding or changing behavior that needs verification.

---

## Philosophy

- Test **behavior**, not implementation
- Feature tests are **mandatory** for all API endpoints and critical flows
- Unit tests only for **complex business logic** (ABAC rules, SLA calculation, assignment resolution)

---

## Stack

| Type | Tool | When |
|------|------|------|
| Feature / API | Pest | Every endpoint, auth, ABAC, tenant isolation |
| Unit | Pest | Pure logic isolated from HTTP/DB where complexity warrants |

---

## Coverage Rules

- **Every new API endpoint:** at least happy path + one authorization failure + one validation failure
- **Tenant isolation:** prove tenant A cannot read tenant B data (separate DB connections in test)
- **ABAC:** prove capability denial and confidential task restrictions
- **Platform provisioning:** feature test with central DB + template tenant DB
- **No controller unit tests** — too thin; cover via feature tests

---

## Test Structure

```
tests/
├── Feature/
│   ├── Api/V1/
│   │   └── Platform/
│   └── Tenancy/
└── Unit/
    └── Modules/
        └── Tracking/
            └── SlaDeadlineCalculatorTest.php
```

---

## Test Data

- Use factories — no hardcoded magic IDs in assertions
- `RefreshDatabase` per test class
- Central vs tenant: use documented test helpers to switch connections
- Use `public_id` in test HTTP calls, not internal ids

---

## Running Tests

```bash
php artisan test
php artisan test tests/Feature/Api/V1/Platform
```

---

## CI Rules

- All tests pass before merge to `main`
- CI runs Pest on every PR and before VPS deploy
- Failing tests block deployment

---

→ **Next:** [release-policy.md](release-policy.md)
