# FB-WEB-02 — Public Landing Page V2.1

> **Superseded in part (2026-10-30, FB-WEB-04, approved, not yet deployed):** the page moves
> to `/`, `/welcome` redirects to it, and the Staff dashboard moves to `/dashboard`. §1's route
> statements and the `/` question in §6 are kept below as the original record. See
> `docs/adr/FB-WEB-04-PUBLIC-HOMEPAGE.md`.
>
> **Correction (2026-10-30):** §2 says the site sends `X-Frame-Options: DENY`. The value read
> from `famboook.com` that day was `SAMEORIGIN`. The rule of rendering the preview in the page,
> never in an iframe, still stands.

**Status:** COMPLETED / DEPLOYED / ACCEPTED. Deployed to Production on 2026-10-30 at
`https://famboook.com/welcome`, commit `be44d923cccdc9e1d7cc3f3fceaaa7f7937da016`, and accepted manually by the project
owner (FB-WEB-02H). Indexing and the role of `/` remain owner decisions (§6). See
`docs/deployment/FB-WEB-02-PUBLICATION-PLAN.md`.

**Design reference:** a Google Stitch static review artifact,
`famboook_landing_page_v2_1.html`. It is deliberately not versioned, because it
carries the superseded operator name and claims listed in §4.

## 1. Route

- `/welcome` lives at `frontend/app/(public)/welcome/page.tsx`, a route group
  outside `(staff)` and `family/`.
- It has no AuthGate, FamilyGate, manifest or service worker. No group layout is
  needed.
- `/` stays the Staff dashboard. `/login`, `/family` and `/family/login` are
  unchanged.
- The page is published as `noindex, nofollow`. Removing that is not approved.

## 2. Preview

Four tabs (`components/landing/platform-preview.tsx`, Radix `Tabs`) show the
application's real presentational components, with data from
`components/landing/demo-fixtures.ts` only.

| Tab | Real components |
|---|---|
| Staff dashboard | `KpiCard`, `StatStrip`, `SegmentedBar`, `LegendItem`, `ActivityItem`, `AppCard`, `SectionHeader` |
| Household registry | `Table`, `Initials`, `Code`, `StatusBadge`, `FamilyStatusBadge`, `RegistryPagination` |
| Family PWA | `WelcomeHeader`, `FamilyIdentityCard`, `HouseholdSummaryCard`, `MemberCard`, `FamilyBrand`, `NotificationRowContent` |
| Notifications | `AnnouncementRowContent`, `NotificationRowContent` (with their badges) |

The preview is rendered in the page, never in an iframe, because the site sends
`X-Frame-Options: DENY`. The Family tab uses the `data-portal="family"` tokens
without the Family layout. Its bottom navigation is a demo-only picture
(`DemoBottomNav`) with no links.

## 3. Safety rules

- **Data:** invented and fixed. No National ID or mobile number exists in the
  fixtures, and codes carry `DEMO`. Every panel states that the data is
  illustrative.
- **Runtime isolation:** nothing in the public module graph imports
  `lib/api/*`, a session, a gate or an authenticated screen at run time; type
  imports are allowed. `tests/landing-page.test.tsx` walks the graph and fails
  otherwise.
- **No navigation or action in a panel:** switched off at the component boundary
  (no `href`, `showMembersLink={false}`, `showMyDataLink={false}`), not only hidden.
- **No request:** tested on every tab.

## 4. Copy changed from the reference

| Reference | Published | Why |
|---|---|---|
| «المركز الفلسطيني للتنمية الثقافية والمجتمعية» | `OPERATOR_DISPLAY_NAME` | The approved name (`lib/legal/operator.ts`, FB-LEGAL-01 D1) |
| «الجهة المالكة والمشغلة», «مشروع تابع» | «تشغيل وإدارة البيانات» | D1 makes no ownership claim |
| The Coordinator reviews or approves requests | The Coordinator submits proposals; Staff review them | `COORDINATOR ≠ REVIEWER` (docs/11) |
| «ضبط صارم», «يضمن», «ضمان الامتثال», «لا يطلع أي مستخدم إلا…» | Described without guarantees | Unsupported absolute or compliance claims |
| «مراجعة قبل اعتماد أي تعديل» | Review of requests before they apply | Staff edit the registry directly under their permissions |
| Requests as generally available | «خاضع للتفعيل», «تُتاح على مراحل» | A gated service (docs/11) |
| Notifications as generally available | Manual in-app announcements only; no automated or push delivery is advertised (FB-WEB-02D) | Manual publication exists; system notifications stay off by default (`config/notifications.php`) |
| `famboook.com/portal`, absolute login URLs | No invented path; `/login`, `/family/login` | No such route; same-site links |
| Gradient placeholder «ف» | `BrandLogo` | The official asset |
| Material Symbols, Tailwind CDN, a separate palette | `lucide-react`, the project's tokens, IBM Plex Sans Arabic | The approved stack; `globals.css` is unchanged |
| Spring-green final button | White button | The spring tokens exist only under the Family scope |

The contact address is `OPERATOR.contactEmail` (`info@pcdcd.org`).
`support@pcdcd.org` is not published.

### Positioning (FB-WEB-02C, owner decision 2026-10-30)

The page addresses family and clan councils first, then coordinators, then
household heads. The Hero copy and CTAs are verbatim as approved. A council
decides, requests to join and delegates a coordinator, but it has no account in
the system (FB-GOV-01 G-A), and PCDCD remains the data management authority
(FB-LEGAL-01 D1). The page therefore promises councils no login or dashboard,
and states that a council acts through its coordinator.

FB-WEB-02E fixes the four roles: councils are the intended institutional
beneficiaries, coordinators and assigned personnel the operational users, PCDCD the
operator, and household heads the Family Portal users.

## 5. Presentation-only extractions

All old exports and behaviour are preserved.

- `components/announcements/announcement-row.tsx`: `AnnouncementStatusBadge`,
  `AnnouncementLevelBadge` (re-exported by `announcement-parts.tsx`) and
  `AnnouncementRowContent`.
- `lib/utils/announcement-labels.ts`: status and audience labels (re-exported by
  `lib/utils/announcement.ts`), so they carry no runtime dependency on the API client.
- `NotificationRowContent` and `NOTIFICATION_ROW_CLASS` in `notification-parts.tsx`.
- `MemberCard.showMyDataLink` and `HouseholdSummaryCard.showMembersLink`, both
  defaulting to `true`.

- `components/family/card/family-card-face.tsx`: `FamilyCardFace`, moved out of
  `family-card.tsx`, with the optional `qrSlot` and `headingAs`. Without them it
  renders as before.

## 6. Open decisions

- Removing `noindex`. `/welcome` itself is published (2026-10-30).
- Whether `/` ever becomes the landing page. That needs the Staff dashboard moved
  (`navItems`, `safeNext`, the sidebar logo, `AuthGate`) and is out of scope here.
- docs/08 §1 still describes `famboook.com` as the Staff Portal. The page is now
  deployed and this update is still outstanding.
- Operational checks before relying on the page's wording. The owner confirmed
  `notifications.mode = ON` and `credentials.family_card_issuance_enabled = true`
  (2026-10-30), which show enablement only. QR verification, PDF download and
  manual announcement delivery have no recorded end-to-end test in Production.

Deferred separately: FB-WEB-03 «فامبوك بالأرقام» (owner's decision, not in progress) and
FB-OPS-01 (the Frontend deployment script and the actual port, 3001; not started).

## 7. Digital Family Card section (FB-WEB-02D-CARD, FB-WEB-02E)

The section uses the product's name, «بطاقة الأسرة الرقمية», and advertises display,
QR verification with restricted public information, and the PDF. It lists no
speculative use.

`components/landing/digital-family-card-section.tsx` follows the preview and shows
the real `FamilyCardFace` with `components/landing/demo-card-fixture.ts`.

| Capability | State | Source |
|---|---|---|
| Viewing in the Family Portal | Implemented | `/family/card`, `FamilyCardController` |
| QR verification | Implemented; no QR when the token cannot be decrypted | `FamilyCardView` |
| Restricted public verification | Implemented | `PublicCredentialController`, `FamilyCredentialPresenter` |
| PDF download | Implemented, for an existing ACTIVE card with a QR | `FamilyCardPdfController` |
| Print | Only through the PDF; no print control | `tests/family-card.test.tsx` |
| Revocation and reissue | Implemented, Staff only | Staff credential routes |
| Expiry | Not found: the statuses are ACTIVE and REVOKED | `CredentialStatus` |

Issuance is behind `FAMILY_CARD_ISSUANCE_ENABLED` (default on).

Safety: the fixture has no token, URL or QR, and its number cannot match
`CredentialNumbers::FORMAT`. The QR block is a non-functional placeholder, and the
card carries a label and a watermark. The section states that the card is not an
official identity document, proves no holder's identity and grants no eligibility.
Public verification shows the current head's short name, so the page does not say
that a scan reveals nothing personal.
