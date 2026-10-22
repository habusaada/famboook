# FB-LEGAL-01A — Read-Only Audit: Legal Documents & Public Pages

| | |
|---|---|
| Status | Completed read-only audit, preserved verbatim (FB-LEGAL-01B Task 1). No finding was added, removed or re-worded after delivery; only the closing question to the reader ("save it as a doc?") is omitted |
| Date | 2026-10-22 |
| Baseline | HEAD `c5d448c` (working tree clean apart from the untracked playbook) |
| Method | Four parallel read-only code audits (frontend routes and layouts; authentication and consent; personal-data inventory; security and infrastructure); key claims re-checked by hand |
| Follow-up | Decisions: `docs/decisions/FB-LEGAL-01-DECISIONS.md` (D1 approved 2026-10-22; D2–D9 pending) |

> Note added at preservation: the copyright and operator wording conflict described in §1 / H1 was resolved by decision D1 (FB-LEGAL-01B). The finding below is kept as it was written.

---

I've finished the read-only audit; nothing was changed. The short answer: Famboook has no privacy policy, terms, data-protection notice, about or contact page anywhere, and no consent or acceptance record of any kind. Meanwhile Family accounts collect identity, contact, children and health data, and the contact-update feature has been live in Production since 2026-10-11. The pages can be added with low technical risk. Most of the legal content depends on facts only PCDCD can confirm.

Basis: HEAD `c5d448c` (working tree clean apart from the untracked playbook). Four parallel code audits, with the key claims re-checked by hand.

## 1. Executive summary
- **No legal surface exists.** There are no `/privacy`, `/terms`, `/data-protection`, `/about` or `/contact` routes, links or text. The frontend has no `middleware.ts` or `proxy.ts`; protection comes only from client-side gates inside layouts.
- **No consent mechanism exists.** No table, column, event type or UI records acceptance of terms or a privacy notice. The only "acknowledgement" in the system is a family confirming it has read an IMPORTANT notification (`notification_recipients.acknowledged_at`). That must never be treated as legal consent.
- **Technically easy.** One shared footer (`SiteFooter`) already appears on all five public screens, so one change reaches all of them.
- **Branding conflict.** The current footer says «© 2026 فامبوك. جميع الحقوق محفوظة» and credits PCDCD only as developer (FB-BRAND-01, `site-footer.tsx:6-12,17`). The requested "© 2026 PCDCD" with PCDCD as legal operator changes that approved text, so PCDCD and the owner must decide.
- **Several things a privacy notice would normally promise are not true today:**
  - no retention beyond the 90-day OTP purge;
  - no erasure path;
  - Staff logins are not audited;
  - every role with `person.view` sees full mobile numbers;
  - the off-server backup step is a placeholder.

  The policy text must reflect what is actually implemented.

## 2. Frontend routes and components

**Public routes**

| Route | File | How it stays public |
|---|---|---|
| `/login` (Staff) | `app/(staff)/login/page.tsx` → `components/auth/login-view.tsx` | `PUBLIC_ROUTES` (`lib/navigation.ts:70`) |
| `/dev-login` | `app/(staff)/dev-login/page.tsx` | local development only (`notFound()` at line 9, plus a 404 rewrite in `next.config.ts`) |
| `/family/login` | `app/family/login/page.tsx` → `components/family/auth/login-form.tsx` | sits outside `family/(portal)` |
| `/family/activate` | `app/family/activate/page.tsx` → `components/family/activation/activation-flow.tsx` | outside the portal |
| `/family/forgot-password` | `components/family/auth/password-reset-flow.tsx` | outside the portal |
| `/verify/[token]` | `components/verify/credential-verification.tsx` | outside both sides; `noindex`, `no-referrer` (`next.config.ts` headers) |

**Guards:**
- **Staff:** `AuthGate` (`components/auth/auth-gate.tsx:27-40`) treats anything not in `PUBLIC_ROUTES` as protected.
- **Family:** `FamilyGate` applies only inside `family/(portal)`.
- **Coordinators:** they sign in through the Family login. Coordinator Space is reached from `/family/account` or the home dashboard (`account-view.tsx:225`, `coordinator-dashboard.tsx:153`).
- **Onboarding:** there is no self-registration. Onboarding is activation by the household head only (`activation-flow.tsx:38`).

**Footer and shared pieces:**
- `components/shared/site-footer.tsx` is used only by `FamilyAuthCard` (`components/family/auth/auth-parts.tsx:58`, covering login, activation and reset), `login-view.tsx:171` and `credential-verification.tsx:125`. Its doc comment wrongly says it also appears in the portals.
- There is no app-version string anywhere; `package.json` says `0.1.0`.
- Reusable pieces:
  - `PageHeader`, `SectionHeader`, `DetailList` (`components/shared/page-layout.tsx`);
  - `AppCard`;
  - `BrandLogo`, `FamilyBrand`;
  - the narrow public-page layout of `credential-verification.tsx:97`.

  `FamilyAuthCard` doesn't fit informational pages: it requires a `step` prop and always shows the OTP safety note.

**Metadata and PWA:**
- `app/layout.tsx:12-23` sets the title "Famboook", an Arabic description, `lang="ar" dir="rtl"`. There is no `generateMetadata`, `robots.txt`, sitemap or `not-found.tsx`.
- The service worker is scoped to `/family` (`lib/pwa/family-service-worker.ts:10`) and caches only the offline page. Pages outside `/family` are unaffected.

## 3. Authentication and consent
- **Activation**:
  - Five public steps: `start`, `send`, `verify`, `resend`, `complete` (`backend/routes/api.php:82-92`), all behind a response-time floor (`FamilyActivationController.php:35-99`).
  - Completion is one transaction (`ActivateFamilyAccountAction.php:66-127`): it creates the user, the person link and the auth identity, and records `ACTIVATION_COMPLETED`.
  - The frontend keeps every step in memory only (`activation-flow.tsx:40-49`).
  - There is no staff-assisted activation.
- **Login, reset, password change, logout:**
  - Family login uses National ID and password with lockouts (`FamilyLogin.php:63-129`).
  - Reset and password change revoke every session (`FamilySessions.php:26-43`).
  - Sessions are cookie-based: database driver, 120 minutes, HttpOnly, SameSite=Lax (`config/session.php:21,35,185,202`); there are no tokens.
- **Staff login** (`LoginRequest.php:61-77`) writes no security event, and the Filament admin login is separate (`AdminPanelProvider.php:40-41`).
- **Existing "acknowledgement-like" records** — none of them is consent:
  - the notification read confirmation (high risk of being confused with consent);
  - `family_household_declarations` (declared household counts, entered by Staff);
  - reviewer attestations in change-request `workflow_events` metadata;
  - a Staff-only mobile-trust checkbox that is never saved (`grant-mobile-trust-dialog.tsx:144`).

  Pilot "with consent" appears only off-system (`docs/09-PILOT-SOP.md:196-197,241`).
- **Docs:** no consent or legal-basis rule exists anywhere in `docs/`. Retention, adult-member privacy and session timeout are all still open items.

**Where an acceptance could safely go:**
- **At activation completion, inside the same transaction** (`ActivateFamilyAccountAction.php:122`).
  - The request class `FamilyPasswordRequest` and the frontend `PasswordStep` are shared with password reset, so the field must be activation-only.
  - Validation must happen before the one-time grant is used up.
  - The error-shape test `FamilyActivationCompleteTest.php:123` must stay green.
- **An interstitial after a version change**, driven by a `/family/me` flag and shown by `FamilyGate`. This means updating the exact-shape tests (`FamilyLoginTest.php:99`, `FamilySideBoundaryTest.php:43,145`, `PreviousSignInTest.php:162`). Coordinators use the family login, so they get it too.
- **Staff:** an `AuthGate` interstitial, plus the Filament login later. `StaffAuthenticationTest.php:123` pins the `/me` keys.
- **Audit logging:** `auth_security_events` can hold an acceptance event. It has user, person, family, IP and a hashed browser identifier, and is immutable. Its metadata allow-list (`AuthSecurityEvent.php:33-51`) would need a version key.

## 4. Data processing and security

Field names only; no values shown.

| Category | Where it's stored | Who sees it |
|---|---|---|
| National ID | `persons.national_id` (plaintext, unique, format check, migration `2026_10_20_090000`) | Staff: masked only, and only for SUPER_ADMIN/ADMINISTRATOR (`PersonResource.php:36-38`); the full-view permission is assigned to no role. Family and Coordinator: masked, with audited one-field reveals. Never on the public card. Full values also sit in import staging and in sensitive beneficiary-list exports |
| Household relationships | `family_memberships`, `relationship_types` | Staff, Family, Coordinator; memberships are ended, never deleted |
| Contact details | `persons.mobile`, `alternate_mobile` (plaintext), `person_mobile_trusts` (fingerprint plus last 2 digits) | **Every Staff role with `person.view` sees full numbers** (`PersonResource.php:31-32`). Family and Coordinator: masked, with reveal |
| Original and current residence | `family_residences` (address text, coordinates, location references) | Staff and Family (no coordinates); not Coordinators |
| Displacement | `family_residences.displacement_status`, `displacement_location_text` | as for residence; corrected in place, so no history is kept |
| Disability and health | `person_health_records` (type, `condition_name`, `details`) | Five Staff roles with `health-record.view`. **The household head sees the condition names of all active members** (open item PDD-018). Exports are yes/no flags behind `assistance.export-sensitive` |
| Children and newborns | `persons.birth_date`; there's no minor flag or guardian data (PDD-020); add-member requests in `change_requests.submitted_data` | Staff, Family; Coordinators see age only |
| Family requests | `change_requests.submitted_data` (plaintext JSON), `workflow_events` (append-only; `internal_note` Staff-only) | masked presentation |
| Notifications | `notification_*` tables (viewed and acknowledged timestamps, follow-up notes) | the family's own rows; notes for Staff and in-scope Coordinators |
| Authentication and activity logs | `auth_security_events` (raw IP, hashed browser identifier), `auth_otp_challenges` (raw IP), `sessions` (IP, browser identifier), `family_activities`, file logs (`LOG_DAILY_DAYS=90` in the template) | not exposed through the API |
| Imports | `import_rows.raw_payload` / `normalized_payload` (plaintext National IDs, mobiles, birth dates); workbooks on the private disk | SUPER_ADMIN only; mobiles show unmasked in reconciliation |

**Security, verified in code or config:**
- **Access separation:** Staff and family-side accounts are kept apart (`AccountSide.php`).
- **Scoping:** the family is always derived on the server (`FamilyAccessResolver.php`). The coordinator scope is enforced on every request, and coordinators never see health, residence, needs or assistance.
- **No analytics:** no analytics or third-party scripts; I re-checked this. Fonts are self-hosted at build time.
- **Cookies:** the session cookie and `XSRF-TOKEN`. One localStorage flag remembers that the PWA install prompt was dismissed.
- **SMS provider:** TweetsMS is the only external processor. It receives the mobile number and the code message only (`TweetsSmsSender.php:17-18`; I re-checked this).
- **No other outbound data:** mail is set to `log`, and notifications are in-app only.
- **Public card verification:** shows only the card number, family code, issue date, clan and branch names, and the head's first and last name (`FamilyCredentialPresenter.php:24-34`).
- **Reports:** exports are aggregates only (`ReportExport.php`).
- **Transport headers:** the nginx template sets HSTS, nosniff, `X-Frame-Options: DENY` and a referrer policy.
- **Backups:** encrypted with age (`backup-db.sh:30`).

**Not implemented, or unknown:**
- **No content-security or permissions policy:** the nginx template sets neither header, and its TLS protocol lines are commented out.
- **No Staff audit trail:** there's no audit table, and `audit.view` is unused.
- **Retention:** the 24-month security-event purge isn't implemented, and nothing prunes `failed_jobs`.
- **Import staging is never purged** (PDD-017).
- **The off-server backup copy is a placeholder** (`backup-db.sh:37-40`), and import workbook files aren't backed up.
- **Private disk:** it is set to `serve => true` (`config/filesystems.php:36`). Only signed links could serve files, and none are generated.
- **Hosting, server location and database encryption:** UNKNOWN.

## 5. Gaps and risks

| Severity | Gap |
|---|---|
| **Critical** | **C1.** Production collects identity, contact, children and health data with no privacy notice or terms anywhere |
| **High** | **H1.** The copyright and operator wording conflicts with the approved FB-BRAND-01 footer |
| **High** | **H2.** No retention periods are implemented beyond OTP (security events, imports, notifications, activity, sessions are all unknown), and there's no erasure or objection path. A notice can't truthfully state retention yet |
| **High** | **H3.** Every role with `person.view` sees full mobile numbers, including REPORTS_VIEWER and DATA_ENTRY |
| **High** | **H4.** The household head sees all adult members' health condition names (PDD-018 still open); the notice must disclose this, or the behaviour must change |
| **High** | **H5.** Staff logins and Staff administration aren't audited in the database; a notice can't claim Staff access is logged |
| **High** | **H6.** The off-server backup and restore test aren't evidenced (the restore rehearsal was waived) |
| **Medium** | **M1.** The notification "acknowledgement" could be confused with consent; the design must keep them separate |
| **Medium** | **M2.** No content-security or permissions policy headers; TLS settings commented out |
| **Medium** | **M3.** Import staging keeps plaintext National IDs and mobiles indefinitely |
| **Medium** | **M4.** Raw IP addresses are kept in the authentication tables (OTP rows 90 days, security events with no implemented purge) |
| **Medium** | **M5.** The private disk's `serve => true` setting |
| **Low** | **L1.** No robots, sitemap or not-found page; static metadata |
| **Low** | **L2.** Out-of-date doc claims: the `SiteFooter` comment, and docs/08:49 ("no uploads") |
| **Low** | **L3.** The default Laravel welcome page loads `fonts.bunny.net`, but nginx makes it unreachable |

## 6. Proposed page architecture
- **Routes:** a new route group `app/(legal)/` with its own layout and no gate: `privacy`, `terms`, `data-protection`, `about`, `contact`. The group name adds nothing to the URL. Keeping it outside `(staff)` and `family/` means no `PUBLIC_ROUTES` change, no `safeNext` change and no service-worker scope.
- **Rendering:** server-rendered, static pages built from one content module, `lib/legal/documents.ts` (document id, version, effective date, Arabic sections).
- **Layout:** reuse the `credential-verification` public pattern (narrow centred column, `BrandLogo`, `SectionHeader`, `DetailList`, `AppCard`, `SiteFooter`). No new tokens or colours.
- **Metadata:** each page sets its title and description in its `metadata`. The robots policy for these pages is a decision; the default is to allow indexing for the public legal pages.
- **Links:**
  - add a `LegalLinks` line inside `SiteFooter`, so one change reaches login, activation, reset, Staff login and verify;
  - in the authenticated areas, add a «الخصوصية والشروط» card on `/family/account` and an item in the Staff `user-menu`;
  - don't touch the bottom navigation or `AppShell`.
- **Version line:** a single constant in `lib/release.ts` ("Famboook V1.0", not `package.json`'s 0.1.0). Show «Famboook V1.0 · © 2026 PCDCD» in `SiteFooter` and on `/about`, pending decision D1.

## 7. Legal content coverage matrix

**Legend:**
- **✓** the repository supports a true statement;
- **⚠** the text must describe a gap or limitation truthfully;
- **PCDCD** only PCDCD can supply or confirm it.

| Topic | Privacy | Terms | Data protection | About | Contact | Basis |
|---|---|---|---|---|---|---|
| Operator and controller identity | PCDCD | PCDCD | PCDCD | PCDCD | PCDCD | footer credits PCDCD as developer only |
| Registration numbers, address, official email, phone | — | PCDCD | PCDCD | PCDCD | PCDCD | none in the repo |
| Data categories (§4) | ✓ | — | ✓ | — | — | migrations and models |
| Sources (paper forms, Staff entry, imports, family requests) | ✓ | — | ✓ | ✓ | — | docs and code |
| Purposes | PCDCD | ✓ (acceptable use) | PCDCD | ✓ (registry and case management) | — | docs/01 |
| Legal basis | PCDCD | — | PCDCD | — | — | not documented anywhere |
| Who can see what (roles, coordinators, household head) | ✓ ⚠ (H3, H4) | ✓ | ✓ ⚠ | — | — | §4 |
| Processors (SMS provider, hosting, backup storage) | ✓ SMS / PCDCD the rest | — | ✓ / PCDCD | — | — | §4 |
| Cookies and local storage | ✓ | — | ✓ | — | — | §4 |
| No analytics or advertising | ✓ | — | ✓ | — | — | verified |
| Retention periods | ⚠ PCDCD | — | ⚠ PCDCD | — | — | only OTP (90 days) and logs (90 days in the template) exist |
| Security measures | ✓ (no certification claims) | — | ✓ ⚠ (H5, H6) | — | — | §4 |
| Data-subject rights (access, correction, erasure, objection) | ⚠ correction only / PCDCD | — | ⚠ / PCDCD | — | ✓ channel / PCDCD | no erasure path |
| Account rules (head only, password secrecy, never share the code, no sharing the account) | — | ✓ | — | — | — | auth code, OTP SMS wording |
| Minors and household members without their own access | PCDCD | — | PCDCD | — | — | PDD-020, CTX-PENDING-016 |
| Notifications in-app only, acknowledgement isn't consent | ✓ | ✓ | ✓ | — | — | ADR FB-NOTIFY-01 |
| Public card verification and what it reveals | ✓ | ✓ | ✓ | — | — | presenter |
| Changes, versions and effective dates | ✓ (mechanism) | ✓ | ✓ | — | — | §8 |
| Version line V1.0 and launch date 2026-10-01 | — | — | — | ✓ / PCDCD | — | your brief |
| Governing law, liability, disputes | — | PCDCD | PCDCD | — | — | — |
| Complaints or incident contact | PCDCD | PCDCD | PCDCD | — | PCDCD | docs/09:335-340 is a placeholder |

## 8. Acknowledgement and versioning design (not implemented)
- **Keep three things separate.** Accepting the Terms of Use governs use of the account. Acknowledging the privacy notice means "I have read it". Neither is consent to data processing. The legal basis for registry processing is a PCDCD decision; the data largely comes from Staff and paper forms, not from the family.
- **Versions** use dated identifiers (for example `terms-2026-10-01`) declared once in backend config `config/legal.php`, with the required versions and effective dates. The frontend content module carries the same ids, and a test keeps the two in step.
- **Storage:** a new append-only table, `legal_acknowledgements`:
  - `user_id`, `document` (TERMS / PRIVACY_NOTICE), `version`, `accepted_at`;
  - `channel` (ACTIVATION / INTERSTITIAL / STAFF_LOGIN);
  - a hashed browser identifier; whether to keep the raw IP is a decision;
  - unique on (user, document, version).

  Each acceptance also writes an `auth_security_events` entry such as `LEGAL_DOCUMENT_ACCEPTED` (a metadata allow-list change) for the audit trail. No migration is written yet.
- **When acceptance is captured:**
  - **Family activation completion**, inside the same transaction: an activation-only request field, validated before the grant is used up, with the checkbox only on the activation flow's password step (not reset).
  - **A version change for existing users:** `/family/me` reports `legal_pending`, and `FamilyGate` shows an interstitial.
  - **Staff:** an `AuthGate` interstitial now; Filament later.
- **Rollout:** stage 1 is notice-only (pages and links, no recorded acceptance). Stage 2 records acceptance. Whether to block account use until acceptance is a PCDCD decision; a block could cut families off from the services they rely on.

## 9. Files expected to change

**Phase 1 — frontend only:**
- New: `app/(legal)/layout.tsx`, plus `privacy`, `terms`, `data-protection`, `about` and `contact` page files.
- New: `components/legal/legal-page.tsx`, `components/legal/legal-links.tsx`, `lib/legal/documents.ts`, `lib/release.ts`.
- Modified: `components/shared/site-footer.tsx`.
- Optional: `components/family/account/account-view.tsx`, `components/layout/user-menu.tsx`.
- Tests to update: `tests/brand.test.tsx` (footer text, 96-122), `tests/credential-verification.test.tsx` (116-119: exactly one link), `tests/activation-flow.test.tsx:121` (fine if the links stay inside the footer), `tests/family-login.test.tsx:73`. New `tests/legal-pages.test.tsx`.

**Phase 2 — backend and frontend:**
- New backend: the migration, a `LegalAcknowledgement` model, a recording action, `config/legal.php`, a request and controller (or a field on activation).
- Modified backend: `AuthSecurityEventType.php`, `AuthSecurityEvent.php` (metadata allow-list), `ActivateFamilyAccountAction.php`, `FamilyCurrentUserResource.php`, `routes/api.php`.
- Modified frontend: `activation-flow.tsx` and the password step (activation only), `family-gate.tsx`, `auth-gate.tsx`, `lib/api/family-auth.ts`.
- Backend tests: the exact-shape tests listed in §3, plus a new `LegalAcknowledgementTest`.
- Docs: docs/02, 04, 06 and 11 entries, and an ADR.

## 10. Phased plan
1. **P0 — decisions** (§11). Nothing ships before D1–D3.
2. **P1 — public pages, footer links and the version line.** Frontend only, with no RBAC or authentication change. Content goes in only once approved; draft text is never deployed.
3. **P2 — notice acknowledgement at activation**, recorded in the same transaction. Needs the backend migration and its own approval.
4. **P3 — interstitials** for existing family and Staff users after a version change. Filament follows.
5. **P4 — closing the gaps the notices depend on** (separate approvals): retention and purges (security events, imports, `failed_jobs`), a Staff access audit, the mobile-number visibility decision (H3), the household-head health visibility decision (H4), security headers, and evidence of off-server backups.

## 11. Decisions needed from PCDCD
1. **D1 — copyright and operator wording.** Keep «© 2026 فامبوك» with PCDCD as developer, or switch to "© 2026 PCDCD" with PCDCD as legal operator.
2. **D2 — controller identity and official contact details:** legal name, registration number, address, email, phone, data-protection or privacy contact, and complaint and incident contact.
3. **D3 — legal basis for processing**, the applicable law, and whether any consent is collected and for what.
4. **D4 — retention periods** for each category in §4, and whether erasure is offered.
5. **D5 — hosting provider and data location**, off-server backup provider and location, and the processor agreement with TweetsMS.
6. **D6 — rules for data subjects:** adult household members without their own access, children and guardians, and how a person can ask to see or correct their data.
7. **D7 — acknowledgement rules:** whether acceptance blocks account use, whether to store the raw IP, the re-acceptance policy, and whether Staff must accept.
8. **D8 — H3 and H4:** keep and disclose the current visibility, or change it.
9. **D9 — robots and indexing** for the legal pages, and the public wording of the V1.0 release line.

## 12. Go / No-Go
- **GO** for P1 engineering (routes, layout, link placement, version line, tests) once D1 is decided. Content goes in only as PCDCD approves it.
- **NO-GO** for publishing any legal text in Production until PCDCD approves the content and confirms D2–D5. Publishing claims the system doesn't meet, such as retention, erasure or audited Staff access, is a legal risk in itself.
- **NO-GO** for P2/P3 acknowledgement until D3 and D7 are decided. Recording "acceptance" without an agreed legal meaning would mislead.

Nothing was modified, committed or pushed. No database or Production system was touched, and no personal data or secrets appear in this report.
