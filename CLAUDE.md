# Famboook — Claude Code Instructions

## Project

Famboook is a Family Registry & Case Management System.

This repository follows an approved architecture and documentation baseline.
Do not make architectural or domain assumptions that contradict the approved
documentation.

## Mandatory Documentation

Before implementing or modifying architecture, domain logic, database design,
permissions, workflows, or major features, read the relevant documents:

- `docs/00-PROJECT-CONTEXT.md`
- `docs/01-PRODUCT.md`
- `docs/02-DATA-DICTIONARY.md`
- `docs/03-BUSINESS-RULES.md`
- `docs/04-DATABASE.md`
- `docs/05-WORKFLOWS.md`
- `docs/06-PERMISSIONS.md`
- `docs/07-ROADMAP.md`

Treat these documents as the approved Famboook Architecture Baseline v1.2.

If an implementation request conflicts with the documentation:

1. Do not silently override the documentation.
2. Identify the conflict.
3. Explain the affected rule or ADR.
4. Wait for an explicit architectural decision before changing the baseline.

## Architecture

### Frontend

- Next.js
- React
- TypeScript
- Tailwind CSS
- shadcn/ui
- Radix UI
- Lucide Icons

### Frontend Data & Forms

- TanStack Query
- React Hook Form
- Zod

### Backend

- Laravel 12
- REST API
- Laravel Sanctum
- Laravel Policies
- Spatie Permission
- Domain Actions / Services
- API Resources

### Database

- PostgreSQL 16+

### Applications

Staff Application:
Custom Next.js application.

Executive Dashboard:
Custom Next.js application.

Family Portal:
Custom Next.js application.

System / High Administration:
Filament.

Filament must NOT become the operational Staff Application.

## Architectural Boundaries

- Next.js is the presentation layer.
- Laravel is the authoritative application and business domain layer.
- PostgreSQL is the canonical persisted source of truth.
- Frontend applications must never access PostgreSQL directly.
- APIs must be versioned under `/api/v1`.
- Controllers must remain thin.
- Business operations belong in reusable Domain Actions.
- Laravel Policies are authoritative for object authorization.
- Spatie Permission provides role and permission management.
- Laravel API Resources control API data exposure.
- Never expose unrestricted Eloquent models through APIs.
- Frontend permission checks are UX only and never replace backend authorization.
- Laravel remains the authoritative validation layer.
- Sensitive files must use private storage.
- Authentication tokens must not be stored in localStorage.

## Domain Guardrails

- Person is an independent entity.
- Family and Person have separate permanent identifiers.
- Paper forms are data sources, not the database model.
- Assessments are separate from permanent family/person records.
- Repeatable information must use child records where defined by the data model.
- Derived statistics must not be stored redundantly unless explicitly approved.
- Preserve historical state.
- Never automatically merge duplicate Persons.
- Sensitive personal, health, and document data requires explicit authorization.
- Household head is domain state and is not equivalent to a system user.
- User and Person are separate concepts.
- Family Portal users may only access authorized family information.
- Family Portal changes to canonical registry information must use the Change Request workflow.
- APPROVED and APPLIED are distinct Change Request states.

## Development Principles

Implement the roadmap incrementally.

Do not attempt to build the entire system in one task.

For a backend feature, consider the complete vertical slice:

1. Migration
2. Model
3. Relationships
4. Validation
5. Domain Action
6. Policy
7. API Resource
8. API Endpoint
9. Tests
10. Frontend integration when applicable

Do not create abstractions merely for abstraction's sake.

Prefer explicit, readable, testable domain code.

## Security

Never commit:

- `.env`
- passwords
- API keys
- database credentials
- production secrets
- real national IDs
- real medical information
- identity documents
- real family registry data

Use synthetic development and test data only.

Never weaken authorization or validation merely to make a test pass.

## Working Method

Before implementing a substantial task:

1. Inspect the existing repository.
2. Read the relevant documentation.
3. Identify existing patterns.
4. State the implementation plan.
5. Implement only the requested scope.
6. Run relevant tests and validation.
7. Report what changed.
8. Report tests executed and their results.
9. Report unresolved issues or documentation conflicts.

Do not modify unrelated files without a clear reason.

Do not make destructive database or repository operations without explicit
authorization.

## Git

Keep commits focused and meaningful.

Do not commit secrets, generated dependencies, build output, or sensitive data.

Do not push or rewrite Git history unless explicitly requested.

## Current Stage

The Architecture and Documentation Baseline v1.2 is complete.

The project is now entering the Technical Foundation stage.

Follow `docs/07-ROADMAP.md` for implementation order.