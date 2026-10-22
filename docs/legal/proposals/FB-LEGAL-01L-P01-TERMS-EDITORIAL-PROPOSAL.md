# FB-LEGAL-01L — P-01 Terms Editorial Proposal: Prohibited-Use Introduction

| | |
|---|---|
| Reference | FB-LEGAL-01L / P-01 (`docs/legal/reviews/TERMS-AR-LANGUAGE-REVIEW.md` §2, class CLARITY) |
| Current approved Terms baseline | `4da00de` (SHA-256 `d98b948810f645fdb38344312d6bb86c8a78f034d94c5da775f29b5ccea74f7e`, verified) |
| Current governance-record commit | `b602d00` |
| Decision status | **OPTION_A_APPROVED** (FB-LEGAL-01L.1, see §0). Originally PENDING_EXECUTIVE_DIRECTOR_APPROVAL. **Option B was not approved** |
| Application status | **APPLIED LOCALLY** (FB-LEGAL-01L.1). Originally NOT APPLIED |
| Publication status | **NOT AUTHORIZED** |
| Nature | Editorial only. No change to the substance, legal scope or obligations is intended. Any applied change still alters the approved text, so it needs renewed Executive Director approval and a new baseline |

## 0. Decision (FB-LEGAL-01L.1)

| | |
|---|---|
| Decision | **OPTION_A_APPROVED** |
| Approving authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Evidence | An explicit management decision in the project conversation. No signature or separate approval document |
| Approved exact wording | «يُعدّ من الاستخدام غير المسموح ما يأتي:» |
| Approved insertion point | Immediately after the heading «## الاستخدام غير المسموح» and before the five existing prohibited-use provisions, as its own paragraph |
| Option B | **Not approved** (no numbering, no bullet markers) |
| Application status | **APPLIED LOCALLY** to `docs/legal/drafts/TERMS.ar.md`. The diff against `4da00de` is 2 lines added (the sentence and its separating blank line) and 0 removed |
| Previous approved text baseline | `4da00de` |
| New Git text baseline | `2e0c99d` (stored-file SHA-256 verified, FB-LEGAL-01L.3) |
| Amended text SHA-256 | `bc1bf806aaa1809872e59658c9701aeb208981c63e202f3c8862b4bfaf4a6aa6` (UTF-8, LF, 7,099 bytes; interim integrity reference). Verify after the commit with `git show <commit>:docs/legal/drafts/TERMS.ar.md \| sha256sum` |
| Publication | **NOT AUTHORIZED** |

§1–§6 below are kept unchanged as proposal history.

## 1. Existing text (`4da00de`, section «الاستخدام غير المسموح», lines 44–51)

```
## الاستخدام غير المسموح

انتحال شخصية غيرك، أو استخدام حساب لا يخصّك.
محاولة تجاوز الصلاحيات أو آليات الحماية، أو الوصول إلى بيانات غير مصرّح بها.
جمع البيانات من المنصة بوسائل آلية، أو نسخها أو نشرها دون إذن.
تقديم معلومات مضلِّلة عن قصد، أو إساءة استخدام الإشعارات أو طلبات التحديث.
أي استخدام يعطّل عمل المنصة أو يضرّ بغيرك من المستخدمين.
عند الاستخدام غير المسموح، قد تتخذ الجهة المشغّلة، وفق الصلاحيات والإجراءات المتاحة، تدابير لتقييد الوصول أو تعليق الحساب أو سحب صلاحيات المنسق. <!-- R-24 -->
```

(The `<!-- R-24 -->` marker is internal and is removed on publication.)

## 2. Assessment

- **How the section renders:** each line is published as a **separate paragraph**. The registry type is `LegalSection = { heading: string; paragraphs: readonly string[] }`, and `legal-page-view.tsx` renders each entry as a `<p>`. The renderer has **no list markup**.
- **The problem:** the five provisions (lines 46–50) are bare noun phrases. They are understood as prohibitions only from the heading. As standalone paragraphs, a phrase such as «جمع البيانات من المنصة بوسائل آلية…» can read as a fragment, or even as a description of a permitted activity.
- **Introductory sentence:** needed. It makes the five lines grammatically complete as items of an announced list.
- **Formatting:** helpful, but optional. True list formatting (bullets) would need a renderer and registry change, which is application code and outside this task. Inside the text, formatting can only be approximated with in-paragraph markers.
- **Scope and wording:**
  - The five provisions themselves need no wording change.
  - The final sanctions paragraph (line 51, R-24 approved wording) stays as it is, outside the list.

## 3. Option A — minimal introduction

**Proposed wording (one new paragraph):**

> يُعدّ من الاستخدام غير المسموح ما يأتي:

**Insertion point:** directly after the heading «## الاستخدام غير المسموح» and its blank line, before «انتحال شخصية غيرك…». It becomes a new line 46; the existing lines move down by one.

**Before / after:**

| Before (`4da00de`) | After (Option A) |
|---|---|
| ## الاستخدام غير المسموح | ## الاستخدام غير المسموح |
| | **يُعدّ من الاستخدام غير المسموح ما يأتي:** |
| انتحال شخصية غيرك، أو استخدام حساب لا يخصّك. | انتحال شخصية غيرك، أو استخدام حساب لا يخصّك. |
| … (lines 47–50 unchanged) | … (unchanged) |
| عند الاستخدام غير المسموح، قد تتخذ الجهة المشغّلة … | عند الاستخدام غير المسموح، قد تتخذ الجهة المشغّلة … (unchanged) |

**Editorial benefit:** the five lines read unambiguously as the content of an announced list of prohibited uses. It is one short sentence in plain language.

**Existing wording changed:** none. One paragraph is added; the five provisions and the sanctions sentence are unchanged.

**Interpretation risk: low.**
- **Not exhaustive:** «يُعدّ **من** … ما يأتي» is partitive ("among prohibited uses are the following"). It does not present the list as exhaustive, matching the current open-ended reading and the catch-all fifth item («أي استخدام يعطّل…»). The scope is unchanged.
- **Redundancy:** the phrase repeats the heading. This is acceptable and usual in Arabic regulatory text.
- **The colon:** it makes the next five paragraphs, but not the sanctions paragraph, the list items. The sanctions paragraph opens with its own phrase «عند الاستخدام غير المسموح», so it reads as separate.

## 4. Option B — introduction with in-text numbering

**Proposed wording:** the same introductory paragraph as Option A, plus an Arabic-Indic ordinal prefix on each of the five provisions. The wording of each provision is unchanged after the prefix.

> يُعدّ من الاستخدام غير المسموح ما يأتي:
> ١. انتحال شخصية غيرك، أو استخدام حساب لا يخصّك.
> ٢. محاولة تجاوز الصلاحيات أو آليات الحماية، أو الوصول إلى بيانات غير مصرّح بها.
> ٣. جمع البيانات من المنصة بوسائل آلية، أو نسخها أو نشرها دون إذن.
> ٤. تقديم معلومات مضلِّلة عن قصد، أو إساءة استخدام الإشعارات أو طلبات التحديث.
> ٥. أي استخدام يعطّل عمل المنصة أو يضرّ بغيرك من المستخدمين.

The sanctions paragraph (line 51) stays **unnumbered** and unchanged.

**Insertion points:**
- the introductory paragraph, as in Option A;
- the prefixes «١. » … «٥. » at the start of the existing lines 46–50 (lines 47–51 after insertion).

**Before / after (first two provisions):**

| Before (`4da00de`) | After (Option B) |
|---|---|
| | يُعدّ من الاستخدام غير المسموح ما يأتي: |
| انتحال شخصية غيرك، أو استخدام حساب لا يخصّك. | ١. انتحال شخصية غيرك، أو استخدام حساب لا يخصّك. |
| محاولة تجاوز الصلاحيات أو آليات الحماية، … | ٢. محاولة تجاوز الصلاحيات أو آليات الحماية، … |

**Editorial benefit:** the list structure is visible even though paragraphs render as plain `<p>`. Individual items can be referred to («البند ٣»).

**Existing wording changed:** the provisions' words are unchanged, but **five existing lines gain a prefix**, so six lines change in total instead of one.

**Interpretation and presentation risk: low to moderate.**
- **Exhaustive reading:** numbered items can give a stronger impression of a closed, exhaustive list. The partitive «من» mitigates this.
- **Bidirectional rendering and digits:** the digit-and-full-stop prefix inside a right-to-left paragraph mixes number and punctuation direction. The convention (Arabic-Indic or Western digits, «١.» or «١-») is an open check for the language reviewer (checklist item 11). The rendering must be verified in the browser.
- **Precedent:** numbering only this section introduces a convention used nowhere else in the Terms or the other legal pages.
- **Maintenance:** later insertions or removals mean renumbering, so each further text change grows.
- **Proper bullet formatting:** needs a separate application change (list support in `LegalSection` and the renderer), and its own approval.

## 5. Recommendation

**Option A.**

- **It fixes the problem fully:** the fragments become items of an announced list.
- **Smallest change:** one added paragraph, with no existing wording altered.
- **Scope preserved:** the scope is identical (partitive, non-exhaustive).
- **No new conventions:** no digit or bidirectional-text conventions, no dependency on rendering.
- **Easy to review:** the smallest diff for the renewed Executive Director approval and the independent language review.

If clearer list presentation is wanted later, it is better done as a separate, approved renderer change that adds real list support for all legal pages, rather than numbering this one section.

## 6. Approval and application path (not started)

1. The Executive Director, PCDCD, approves the exact wording: Option A, Option B, or an amended form.
2. Only then is the change applied to `TERMS.ar.md` in a separate change. Its commit becomes the new approved baseline, replacing `4da00de`, with its SHA-256 recorded in `docs/legal/approvals/TERMS-APPROVAL-RECORD.md`.
3. The independent Arabic review examines the resulting text.

Until then, `TERMS.ar.md` stays at `4da00de` and publication stays NOT AUTHORIZED.
