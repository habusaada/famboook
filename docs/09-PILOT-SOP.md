# Famboook — Pilot Standard Operating Procedure

**Document:** `09-PILOT-SOP.md` · **Version:** 1.3 · **Date:** 2026-10-14  
For every Staff member entering or correcting real Pilot data.

## Before entry

1. Check the address bar: **https://famboook.com** (padlock shown). Never
   enter real data anywhere else.
2. Log in with **your own** account. Never use someone else's.
3. Confirm the paper form number and write it in "رقم النموذج الورقي".
4. **Search first** (Family code, head's name, any member's name or Person
   code) before creating a Family or a Person.

## During entry

- Enter only what the form says. **Do not invent** a date of birth or a
  National ID — leave them empty; empty means "unknown".
- Leave governorate/city empty if the form does not give them.
- If the duplicate National ID warning appears, **stop**: open the listed
  existing record and check it. Do not create the person again.
- Save the Family, then add members; check each relationship before saving.

## Corrections

- DATA_ENTRY: correct ordinary Person data, residence/displacement and a
  member's relationship.
- ADMINISTRATOR only: ending a wrong membership (with a reason) and
  correcting a National ID.
- There is no delete and no merge. Do not work around this (for example by
  renaming a record to reuse it). Report doubtful cases to an ADMINISTRATOR.

## Mobile trust (ADMINISTRATOR / SUPER_ADMIN)

On the Person page, card «توثيق رقم الجوال» (FU-15):

- **Grant** only after you have actually verified that the person's
  **currently registered** mobile belongs to them — in person, by your own
  call-back, or by reviewing an authorized record. Choose that method and
  confirm. You never type a number; if the registered number is wrong or
  missing, correct the person's mobile first.
- **Revoke** when the number is lost, is not the person's, was verified in
  error, or for an administrative reason. Revoking blocks password recovery
  by that number; it does **not** close the family's account or log them
  out.
- A revoked or outdated trust is never "restored". To trust the number
  again, verify it again and grant: a new record is created and the old one
  stays in the history.
- Every grant and revoke is recorded in your name.

## طلبات تحديث السكن — التجربة المضبوطة (controlled pilot)

The first family request type, RESIDENCE_UPDATE: a household head proposes
a **correction** of the family's current residence; Staff review it and
APPLY it. It is closed until the owner authorizes the pilot. The technical
release and switch steps are in docs/08 §16b.

### Before the pilot (system administrator)

- [ ] The deployed commit equals the approved SHA; `git status` on the
      server is clean; deployment was done as `deploy` (docs/08 §16b).
- [ ] A backup was taken right before the deployment and the restore test
      (docs/08 §13) has passed.
- [ ] `famboook:verify-permissions` passed after the deployment; no
      migration ran (this release has none).
- [ ] The read-only smoke checks of docs/08 §16b passed with the mode
      **OFF**.
- [ ] Written pilot authorization from the owner: dates, the pilot Family
      (by Family code only), the household head's account, the Staff
      reviewer, and who sets and resets the submission mode.

### Choosing the pilot Family

- Only the Family named in the written authorization. **Never** pick a
  random Production Family or "try it" on another one.
- The household head has an active Family Portal account and is the
  current head; the Family has a current residence.
- The head agrees to take part and knows the request is reviewed by Staff.
- The authorization names the Family by its Family code; the system
  administrator turns it into the server-only allowlist entry (docs/08
  §16b). Staff never see or handle the allowlist.
- Refer to the Family by its Family code (`FAM-…`) and the request by its
  code (`CRQ-…`) only. Never write a National ID, mobile number, OTP,
  password, card number or QR link in notes, chats or screenshots.

### Opening

The administrator sets the mode to `PILOT` with the pilot Family on the
allowlist (docs/08 §16b, "Opening the pilot") for the agreed window. Only
that Family can start a request; every other household head still sees
«+» disabled. Never use `GENERAL` for the pilot.

### Family submission (with the household head)

1. In the Family Portal «+» is active; «طلب جديد» lists «تحديث بيانات السكن».
2. The form shows the family's current residence. The head changes only
   what is actually wrong, adds a short reason if useful, and sends.
3. Check: the request opens with a code `CRQ-…` and status «تم تقديم
   الطلب»; «أسرتي» still shows the **old** residence (nothing changes
   before APPLY).

### Staff review (REVIEWER / ADMINISTRATOR)

1. «طلبات تحديث البيانات» → open the request.
2. Compare «البيانات الحالية» with «التعديل المطلوب». Check them against
   the paper form or the family's statement as your supervisor directs.
3. «بدء المراجعة».
4. If something is unclear: «إرجاع للاستكمال» with a clear
   message for the family (they see it); an internal note is Staff-only.
5. Then «اعتماد الطلب» or «رفض الطلب» with a reason.
6. **APPROVED is not done.** «معتمد بانتظار التطبيق» means nothing has
   changed in the registry yet.

### APPLY (explicit Staff action)

1. On the approved request: «تطبيق التعديل» (after a failure: «إعادة محاولة التطبيق»).
2. Check: status «مطبّق»; the Family's residence page shows the new
   values and nothing else changed; the Family activity shows «تم تعديل
   بيانات السكن» (or «تم تعديل بيانات النزوح») and «طُبّق طلب تحديث بيانات على سجل الأسرة»; the family
   sees «تم تطبيق التعديل».
3. If APPLY refuses because the data changed (the residence was edited
   after the request), do **not** edit data to force it: read the current
   residence, then reject as no longer applicable or ask the family for a
   new request.
4. If APPLY fails unexpectedly, it can be retried; nothing was written.
   Retry once; if it fails again, stop and report.

### Negative checks (on the pilot Family only, during the window)

| Check | Expected |
|---|---|
| Mode OFF: «+» | Disabled; «طلباتي» still opens the history |
| Mode OFF: open the form URL | «طلبات تحديث السكن غير متاحة حاليًا» |
| Mode PILOT, a Family not on the allowlist (authorized test account only) | «+» disabled; the form says unavailable; «طلباتي» opens |
| A Staff account opening the Family Portal | Refused |
| Another Family's request link | «الطلب غير متاح» |
| A second request while one is open | Refused: a request is already open |
| Sending without changing anything | Refused: no change |
| Location filled while «غير نازحة» | Not possible / refused |
| Residence edited by Staff after the request | Approve / APPLY refused (data changed); the newer data is kept |
| Cancel before approval | «ملغى»; residence unchanged |
| Cancel after approval | Not offered |

Do not run checks that need another real Family, and never create test
requests on a non-pilot Family.

### Closing

The administrator sets the mode back to `OFF` at the end of the window. Open
requests stay visible and can still be reviewed, applied, answered or
cancelled.

### Evidence and sign-off

Record, without personal data: date and time, Family code, request code,
each status reached, who acted (Staff name), and pass / fail for each
step and negative check. Screenshots only if the National ID, mobiles and
card are not visible (crop or blur first).

Acceptance (all required):

- [ ] The residence did not change before APPLY, and changed exactly as
      proposed after it.
- [ ] Every status shown to the family matched the Staff view.
- [ ] Every negative check gave the expected result.
- [ ] No error page, no raw error text, no data of another Family seen.
- [ ] The mode was `OFF` again at the end (`famboook:change-requests-check`).
- [ ] Signed by the pilot Staff reviewer and the owner.

If anything is wrong: set the mode to `OFF` first (docs/08 §16b, "Feature
shutdown"), then report — never delete or edit a request or its history.

## طلبات التنسيق — التجربة المضبوطة (coordinator pilot)

Coordinator-submitted requests (CO-02): a Coordinator proposes **adding a
member** to a Family in their scope (PERSON_ADD) or **registering a new
Family** placed in their scope (FAMILY_ADD). Staff review, approve and
APPLY as for every request; nothing changes in the registry before APPLY.
It is closed until the owner authorizes the pilot. The release and switch
steps are in docs/08 §16d.

### Before the pilot (system administrator)

- [ ] The release of docs/08 §16d is deployed at the approved SHA, as
      `deploy`, with the coordinator mode **OFF**; its smoke checks passed.
- [ ] `famboook:change-requests-check` exits 0: «Coordinator request
      permissions: OK», the three CO-02 migrations «applied».
- [ ] A backup was taken right before the deployment; the restore test
      (docs/08 §13) has passed.
- [ ] **Written pilot authorization from the owner**, naming:
      - the dates of the window;
      - ONE pilot Coordinator (person and account) and their scope —
        one Clan, Branch Group or Branch, assigned through «إدارة
        المنسّقين» (never edited in the database);
      - for PERSON_ADD: the Family code(s) in that scope that agreed to take
        part, and the person to add (with consent);
      - for FAMILY_ADD: the household to register (with consent) and its
        Clan / Branch inside the scope;
      - the Staff reviewer (REVIEWER with `change-request.apply`);
      - who sets and resets the coordinator mode;
      - the incident contact below, filled in.

### The pilot Coordinator

- An eligible household head with an active Family Portal account, the
  COORDINATOR role and exactly the authorized scope. Check in Coordinator
  Space that «نطاق التنسيق» shows that scope and nothing more.
- Briefed: only the authorized Families and person(s); identity values are
  typed from the document, never guessed; the National ID is never written
  in a reason, note, chat or screenshot.
- Their own household stays in the Family Portal («أسرتي») as before; a
  request about another Family appears there under «قدّمه المنسّق».

### Opening (OFF → PILOT)

The administrator, for the authorized window only (docs/08 §16d):

```text
# server .env
CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE=PILOT
CHANGE_REQUESTS_COORDINATOR_PILOT_USER_IDS=<the pilot Coordinator's user id>

sudo -u deploy php artisan config:clear
sudo -u deploy php artisan optimize
sudo systemctl reload php<version>-fpm
sudo -u deploy php artisan famboook:change-requests-check
#   expect: Coordinator submission mode (effective): PILOT,
#   "1 id(s), 1 eligible Coordinator account(s)", no warnings
```

Never use `GENERAL` for the pilot. The family mode is not changed.

### PERSON_ADD (with the pilot Coordinator)

1. Coordinator Space → «طلبات التنسيق» → «طلب جديد» → «إضافة فرد إلى أسرة».
2. Search the authorized Family by code; choose it. Enter the member's
   name, nine-digit National ID (from the document), sex, relationship
   (never «رب الأسرة»), optional date of birth / marital status / mobile.
3. Send. Check: the request opens with `CRQ-…`, «تم تقديم الطلب»; the
   Family's members are unchanged.
4. The household head (with consent) sees it in «طلباتي» with «قدّمه
   المنسّق: <name>» and **no** action.

### FAMILY_ADD (with the pilot Coordinator)

1. «طلب جديد» → «تسجيل أسرة جديدة».
2. Choose the authorized Clan and Branch (only the scope's placements are
   offered; «دون فرع» only for a Clan-level scope). Enter the head's
   identity and the ORIGINAL residence only.
3. Send. Check: «تم تقديم الطلب»; **no Family exists yet** (Staff search
   by the head's name finds nothing new).

### Staff review (REVIEWER)

1. «طلبات تحديث البيانات» → filter «جهة التقديم: عبر منسّق» → open.
2. PERSON_ADD: as for a household's add-member request — «اعتماد الطلب»
   needs both attestations and the National ID typed from the document.
3. FAMILY_ADD: the request shows «الأسرة المقترحة», the proposed Clan /
   Branch and «تُنشأ عند تطبيق الطلب». The Staff-only line «مطابقة رقم
   الهوية في السجل» must say no Person holds the ID — otherwise reject.
   Approval attests the head's identity against an official document
   (typed ID) **and** that the person is genuinely this household's head
   and the Family belongs to the proposed Clan and Branch (only the Clan
   when there is no Branch). It certifies no social, health or assistance
   information.
4. If something is unclear: «إرجاع للاستكمال» (the Coordinator answers);
   a request that is never answered can now be closed with «رفض الطلب».
5. **APPROVED is not done.** Then «تطبيق التعديل» / «تسجيل الأسرة في
   السجل». Check: PERSON_ADD — the member appears in the Family; FAMILY_ADD
   — a new Family exists in exactly the proposed Clan / Branch, the head is
   a member with the HEAD relationship, the residence holds only the
   original residence, and the Coordinator sees «رمز الأسرة».

### Negative checks (pilot accounts only, during the window)

| Check | Expected |
|---|---|
| Mode OFF: Coordinator «طلب جديد» | Not offered; a direct POST is refused (503) |
| Another Coordinator (authorized test account) in PILOT | «طلب جديد» not offered; refused (503) |
| A Family outside the scope (by code) | «الأسرة غير متاحة ضمن نطاقك»; nothing created |
| A Clan / Branch outside the scope (FAMILY_ADD) | One generic refusal on «مكان الأسرة»; no detail |
| Same member (same National ID) twice for one Family | Refused: a request is already open |
| Same proposal sent twice (double click / network retry) | One request only |
| National ID already in the registry (FAMILY_ADD) | Submitted normally (no hint); Staff approval refuses it |
| Household head on a Coordinator's request | Sees it with the Coordinator's name; cannot cancel or answer |
| Coordinator on another Coordinator's request link | «الطلب غير متاح» |
| Scope revoked during the window (Staff action, authorized) | The Coordinator no longer sees the request; Staff still do |
| Coordinator views | National ID and mobile masked; no registry match, no Staff names, no internal notes |
| Cancel before approval / after approval | «ملغى» / not offered |

Never run checks on Families or people not named in the authorization.

### Closing and shutdown

At the end of the window — or at once on any problem — the administrator
sets `CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE=OFF` (docs/08 §16d,
"Feature shutdown") and confirms with `famboook:change-requests-check`.
Open requests stay visible and can still be reviewed, applied, answered,
rejected or cancelled.

### Evidence and sign-off

Record, without personal data: date and time, Family code(s) and request
code(s), each status reached, who acted (Staff name), and pass / fail for
each step and negative check. Screenshots only with National IDs, mobiles
and cards cropped or blurred.

Acceptance (all required):

- [ ] Nothing changed in the registry before APPLY; after APPLY exactly the
      proposal (member added / Family registered in the exact placement).
- [ ] The Coordinator never saw a Family, Clan or Branch outside the scope,
      nor any health, assistance, needs or current-residence data.
- [ ] Every negative check gave the expected result.
- [ ] No error page, no raw error text, no data of another Family seen.
- [ ] The coordinator mode was `OFF` again at the end.
- [ ] Signed by the pilot Staff reviewer and the owner.

### Incident (coordinator pilot)

Data shown to the wrong person, a request about an unauthorized Family, an
unexpected Family or member created, or any doubt: **set the coordinator
mode to OFF first**, then contact the system administrator below. Never
delete or edit a request, its history, or a created record to "undo" it —
a request is rejected through the workflow, and a created record is
corrected through the normal registry procedure, with the owner.

## Security

- Never share passwords or accounts; never write passwords on forms.
- Log out when leaving a shared device.
- Do not photograph, export, print or store family data outside Famboook
  unless your supervisor explicitly requires it.

## Incident

If you suspect unauthorized access, a lost device, or data shown to the
wrong person: **stop entry immediately**, do not try to fix it yourself, and
contact the designated system administrator: **<name / phone to be filled
by the owner>**.
