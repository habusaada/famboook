# FB-LEGAL-01D — Launch-Day Checklist: Famboook Support Email

| | |
|---|---|
| Status | **Checklist only — nothing executed.** The switch is manual and separately approved; it is never automated, and the mailbox is never assumed active |
| Date | 2026-10-22 |
| Current state | `/contact` shows **only** `info@pcdcd.org` (ACTIVE, official institutional email) as the channel for inquiries and support (`frontend/lib/legal/operator.ts` `OPERATOR.contactEmail`). `support@pcdcd.org` is **PLANNED, not active**, and appears nowhere in the application. `media@pcdcd.org` is never shown on Famboook pages |
| Related | `docs/decisions/FB-LEGAL-01-DECISIONS.md` (D2); `docs/legal/drafts/CONTACT.ar.md`; `docs/legal/drafts/FB-LEGAL-01C3-REVIEW.md` (R-42) |

---

## 1. Preconditions (stop if any is not met)

| # | Check | Owner | Evidence |
|---|---|---|---|
| P1 | `support@pcdcd.org` has been created by PCDCD | PCDCD IT | confirmation |
| P2 | A named person or team reads the mailbox, with cover during the launch period | PCDCD management | name(s) recorded |
| P3 | Handling rules agreed: what support answers, what goes to `info@pcdcd.org`, never asking for passwords or verification codes | PCDCD management | short written note |

## 2. Mailbox verification (before any code change)

| # | Test | Expected | Result |
|---|---|---|---|
| M1 | Send a test message to `support@pcdcd.org` from an external address | delivered; visible to the mailbox owner | |
| M2 | Reply from `support@pcdcd.org` to that external address | delivered, not marked as spam; sender shown as the support address | |
| M3 | Send to `support@pcdcd.org` from a mobile mail app | delivered | |
| M4 | Confirm `info@pcdcd.org` still receives and sends normally | unchanged | |

Do not continue unless M1–M4 all pass. Record the date, the tester and the results.

## 3. Content update (local, then the normal review and release process)

1. In `frontend/lib/legal/operator.ts`, add the support address beside the institutional one, for example `supportEmail: "support@pcdcd.org"`, with its verification date. **Keep `contactEmail` (`info@pcdcd.org`).**
2. In `frontend/app/(legal)/contact/page.tsx`, use the two-channel text prepared in `docs/legal/drafts/CONTACT.ar.md` §"Launch-day version":
   - support address: Famboook accounts, family data, corrections, privacy questions;
   - institutional address: general institutional correspondence;
   - keep the safety note (never send a password or code).
3. Update the tests in `frontend/tests/legal-pages.test.tsx`:
   - the support address is displayed as a `mailto:` link;
   - the institutional address remains;
   - the media address never appears;
   - the served-code e-mail check allows exactly these two addresses, both declared only in `operator.ts`.
4. Update `docs/decisions/FB-LEGAL-01-DECISIONS.md` D2: `support@pcdcd.org` ACTIVE, with the verification date.
5. The three pending policy drafts (privacy, terms, data protection) name `support@pcdcd.org`; they become publishable on this point once it is active (R-42). Their own approvals are still required.

## 4. Verification before deployment

| # | Check | Expected |
|---|---|---|
| V1 | Frontend tests, typecheck, lint, production build | all pass |
| V2 | `/contact` renders without signing in, RTL, desktop and phone | both addresses visible; links open the mail app with the right address |
| V3 | Footer and menus | links unchanged (`/about`, `/contact`); pending policies still unlinked |
| V4 | Built browser JavaScript | no pending policy text; only the approved addresses |
| V5 | Click each `mailto:` link | the right address is filled in |

## 5. Deployment and rollback

- **Deployment:** the frontend change follows the normal approved release procedure. It is a frontend-only change (no backend, migration or permission).
- **Rollback:** if the support mailbox fails after launch, revert to the `info@pcdcd.org`-only page (the current version) and redeploy the frontend. Record the incident and inform PCDCD management.

## 6. Sign-off

- [ ] P1–P3 met
- [ ] M1–M4 passed and recorded
- [ ] Content, tests and decision register updated (§3)
- [ ] V1–V5 passed
- [ ] Release approved and deployed
- [ ] D2 updated with the activation date
