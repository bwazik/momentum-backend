# Spec & Plan Creation Guide — Momentum Backend

> Use these prompt templates to create new specs and implementation plans.
> The agent MUST read all `docs/ai/` files before creating any spec or plan.

---

## Mandatory Pre-Work

Before using either prompt, the agent MUST read ALL of these files in order:

1. `docs/ai/context.md`
2. `docs/ai/roadmap.md`
3. `docs/ai/architecture.md`
4. `docs/ai/coding-standards.md`
5. `docs/ai/security-policy.md`
6. `docs/ai/testing-policy.md`
7. `docs/ai/release-policy.md`
8. `docs/ai/glossary.md`
9. `docs/ai/spec-creation-guide.md` (this file)

If the agent skips any file, the spec or plan will miss critical constraints.

---

## Prompt 1: Create a New Spec

Copy and paste the following prompt. Fill in the bracketed sections before sending.

```
Create a new feature spec for the following feature:

**Feature description:**
[Describe what you want to build in plain English. Focus on WHAT and WHY — not how to implement it technically.]

**Spec number:**
[Look at the existing folders in specs/ and use the next number. e.g. 004]

**Feature name (short, kebab-case):**
[e.g. user-invitations, export-to-csv, pin-code-login]

---

## Instructions for the agent:

1. Read ALL files in `docs/ai/` in order:
   - context.md → roadmap.md → architecture.md → coding-standards.md → security-policy.md → testing-policy.md → release-policy.md → glossary.md → spec-creation-guide.md
2. Read the spec template at `specs/_example/001-example-feature/spec.md`.
3. Check `docs/ai/roadmap.md` for the correct milestone and any dependencies.
4. Check existing specs in `specs/` for related or overlapping features.
5. Create the folder: `specs/[number]-[feature-name]/`
6. Create `specs/[number]-[feature-name]/spec.md` with:
   - Header: number, date, status (`draft`), milestone, depends on, provides APIs, contract status (`draft`), frontend spec, author, branch, base branch
   - A clear problem statement (WHY this feature is needed)
   - User stories in "As a [role], I want to [action], so that [outcome]" format
   - Specific, testable acceptance criteria (checkboxes)
   - **Non-Functional Requirements section** that references `coding-standards.md` for:
     - Pagination strategy (cursor vs full list)
     - Caching strategy (what to cache, TTL, invalidation)
     - Rate limiting (which tiers apply)
     - Transaction boundaries (which operations need DB::transaction)
     - Error handling and logging (module channel name)
     - Enum usage (which enums to create)
     - Queue jobs (what to dispatch async)
   - Explicit out-of-scope items
   - Any open questions that need answering before implementation
7. Do NOT create `plan.md` yet — that comes after the spec is reviewed and approved.

**Do not start implementing.** This prompt is spec creation only.
**You can know the next spec from the roadmap.md**
**You can access any files you need outside the projects from the blueprint folder**
**You can access any plan.md or spec.md from older spec as a reference if you needed to**
```

---

## Prompt 2: Create an Implementation Plan

Use this AFTER a spec has been reviewed and approved. The agent reads the spec plus all docs/ai files to produce a detailed technical plan.

```
Create plan.md for specs/[number]-[name]/ based on the approved spec.

---

## Instructions for the agent:

1. Read ALL files in `docs/ai/` in order:
   - context.md → roadmap.md → architecture.md → coding-standards.md → security-policy.md → testing-policy.md → release-policy.md → glossary.md → spec-creation-guide.md
2. Read `specs/[number]-[name]/spec.md` completely.
3. Read `docs/ai/architecture.md` and `docs/ai/security-policy.md` for module boundaries and security constraints.
4. Read dependency spec `plan.md` files listed in the spec's `Depends on:` field.
5. Read the existing module code in `app/Modules/` to match established patterns (controller structure, service structure, resource structure, etc.).
6. Create `specs/[number]-[name]/plan.md` with the following sections:

### Required Sections

- **Open Questions Resolved** — List every open question from the spec and the decision made. If any remain unresolved, mark them `<!-- TODO: verify -->`.
- **Technical Approach** — One-line summary + key decisions with short rationale.
- **Affected Modules / Files** — List every new file and modified file with brief change description.
- **Implementation Notes** — For each major component, include:
  - One-line summary of the approach
  - Key decisions (bulleted) with short rationale
  - Exact files to edit (full paths)
  - Minimal API contracts or function signatures (request/response examples)
  - Minimal, copy-pasteable code snippets for core logic (match project language)
  - Two simple test cases (input → expected output)
  - Explicit notes on which `coding-standards.md` rules apply (cursor pagination, caching, transactions, logging, enums, rate limiting)
- **Execution Order** — Numbered steps with dependencies.
- **API Contract Summary** — Method | Endpoint | Auth | Description table.
- **What to Test Manually** — Numbered list of manual test scenarios covering happy paths, edge cases, and NFRs (caching, rate limiting, concurrent writes, etc.).

### Implementation Detail Requirements

Write implementation details inline in the plan so that a low-capacity model can implement the easy parts directly. Across the existing plan sections (Technical Approach, Affected Modules / Files, Implementation Notes) include, as appropriate and copy-paste ready:

- One-line summary of the approach
- Key decisions (bulleted) with short rationale
- Exact files to edit (paths)
- Minimal API contracts or function signatures (request/response examples)
- Minimal, copy-pasteable code snippets for core logic (match project language)
- Two simple test cases (input → expected output)

Keep these items concise and avoid long prose; mark uncertain details with `<!-- TODO: verify -->` so humans can review.

7. Ensure every section of the plan references the applicable rules from `coding-standards.md`:
   - Which endpoints use cursor pagination vs full list
   - What to cache and what TTL to use
   - Which operations need `DB::transaction()`
   - Which service methods need try/catch with module logging channel
   - Which enums to create and where
   - Which rate limiting tiers apply to which endpoints
   - What to dispatch to queues vs handle synchronously
8. After generating the plan, include a short Implementation Prompt that can be given directly to another coding LLM to implement the feature according to the approved spec and generated plan.
9. After implementation is completed, perform a full review of the implementation against the approved spec and plan.

   Generate a concise issues report containing:
   - Missing files
   - Missing functionality
   - Incorrect logic
   - Security issues
   - Architecture or standards violations

   Include file paths and recommended fixes so the report can be given directly to another coding LLM for remediation.

**Do not start implementing.** This prompt is plan creation only.
**You can access any files in the current codebase to know about the existing code (ONLY IF NEEDED)**
**You can access any plan.md from older spec as a reference if you needed to**
**You may update any part of the spec.md if you discover that it is no longer relevant or does not match the current codebase**
```

---

## Checklist: Spec Review Criteria

Before approving a spec, verify:

- [ ] Problem statement is clear and explains WHY
- [ ] User stories cover all roles (tenant admin, internal user, system module)
- [ ] Acceptance criteria are specific, testable, and have checkboxes
- [ ] Non-Functional Requirements section exists and references `coding-standards.md`
- [ ] Pagination strategy specified for every list endpoint (cursor vs full)
- [ ] Caching strategy specified for read-heavy data
- [ ] Rate limiting specified for each endpoint group
- [ ] Out-of-scope items are explicit
- [ ] Open questions are listed
- [ ] Dependency on other specs is stated
- [ ] Provides APIs section lists all endpoints

## Checklist: Plan Review Criteria

Before approving a plan, verify:

- [ ] Every open question from the spec has a resolution
- [ ] Technical approach is clear with key decisions explained
- [ ] All new and modified files are listed with full paths
- [ ] Implementation notes include copy-pasteable code snippets
- [ ] Database transactions specified for all multi-write operations
- [ ] Try/catch with module logging channel specified for all service methods
- [ ] Enums specified instead of magic numbers
- [ ] Cursor pagination specified for large table list endpoints
- [ ] Caching keys, TTL, and invalidation events specified
- [ ] Rate limiting specified per endpoint group using `RateLimits` constants
- [ ] Domain events implement `ShouldDispatchAfterCommit`
- [ ] API contract summary table is complete
- [ ] What to Test Manually section covers NFRs

---

→ **Next:** [context.md](context.md) (start of reading chain)
