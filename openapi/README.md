# OpenAPI Contract

**Source of truth:** Scramble (`dedoc/scramble`) generates OpenAPI from Laravel routes, Form Requests, and API Resources.

## Committed artifact

`openapi.json` in this directory is the contract handoff to the frontend workspace.

## Workflow

1. Implement or change API endpoints in a spec branch
2. Run Scramble export (command TBD at Laravel scaffold)
3. Commit updated `openapi.json`
4. Set spec `Contract status:` to `stable` when reviewed
5. Frontend regenerates TypeScript types from this file (CI + local)

## Local path for frontend

From `../frontend/`:

```
../backend/openapi/openapi.json
```

## Placeholder

`openapi.json` will be generated when the Laravel application scaffold exists and first API routes are registered.
