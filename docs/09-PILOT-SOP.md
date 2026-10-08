# Famboook — Pilot Standard Operating Procedure

**Document:** `09-PILOT-SOP.md` · **Version:** 1.2 · **Date:** 2026-10-08  
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
- [ ] The read-only smoke checks of docs/08 §16b passed with the switch
      **off**.
- [ ] Written pilot authorization from the owner: dates, the pilot Family
      (by Family code only), the household head's account, the Staff
      reviewer, and who opens and closes the switch.

### Choosing the pilot Family

- Only the Family named in the written authorization. **Never** pick a
  random Production Family or "try it" on another one.
- The household head has an active Family Portal account and is the
  current head; the Family has a current residence.
- The head agrees to take part and knows the request is reviewed by Staff.
- Refer to the Family by its Family code (`FAM-…`) and the request by its
  code (`CRQ-…`) only. Never write a National ID, mobile number, OTP,
  password, card number or QR link in notes, chats or screenshots.

### Opening

The administrator turns the switch on (docs/08 §16b, "Opening the pilot")
for the agreed window only. Opening it opens the channel for every active
household head account, so keep the window short and close it after.

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
| Switch off: «+» | Disabled; «طلباتي» still opens the history |
| Switch off: open the form URL | «طلبات تحديث السكن غير متاحة حاليًا» |
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

The administrator turns the switch off at the end of the window. Open
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
- [ ] The switch was off again at the end.
- [ ] Signed by the pilot Staff reviewer and the owner.

If anything is wrong: turn the switch off first (docs/08 §16b, "Feature
shutdown"), then report — never delete or edit a request or its history.

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
