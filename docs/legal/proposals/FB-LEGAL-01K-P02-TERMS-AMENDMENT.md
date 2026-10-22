# FB-LEGAL-01K — P-02 Terms Amendment: Conditional Privacy Policy Reference

| | |
|---|---|
| Status | **DECIDED (FB-LEGAL-01K.1).** The exact wording of a **revised** Option B is APPROVED and APPLIED LOCALLY; see §0. §1–§9 are kept unchanged as historical proposal material (originally "PROPOSED — NOT APPROVED FOR APPLICATION"). Publication: NOT AUTHORIZED |
| Management decision | P-02 treatment direction **APPROVED**: the Terms must not imply that the Privacy Policy is already published or accessible. Any reference to it must make clear that its provisions become available through the platform only once it is actually published |
| Decision date | 2026-10-22 |
| Scope of the decision | The direction only. **No replacement wording is approved.** Neither option below has been approved by the Executive Director |
| Original approved text baseline | `c937176` (`docs/legal/drafts/TERMS.ar.md`; TERMS-FINAL-TEXT-APPROVAL, recorded at `27b7041`) |
| Effect on the existing approval | The approval of `c937176` stays valid for the unchanged text. It does **not** extend to an amended text |
| Source finding | P-02, `docs/legal/reviews/TERMS-AR-LANGUAGE-REVIEW.md` §2 |

## 0. Final management decision (FB-LEGAL-01K.1)

| | |
|---|---|
| Decision | P-02 exact replacement wording |
| Status | **APPROVED** |
| Authority | Executive Director, PCDCD |
| Decision date | 2026-10-22 |
| Selected wording | «لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية. وتُبيِّن سياسة الخصوصية، وهي وثيقة مستقلة عن هذه الشروط، كيفية معالجة البيانات الشخصية، وتُتاح عبر المنصة في حال نشرها.» |
| Relationship to the proposal | **Revised Option B:** «عند نشرها» in the proposed Option B (§3, §8) is replaced by «في حال نشرها». The original Option B wording was **not** approved unchanged |
| Application status | **APPLIED LOCALLY** to `docs/legal/drafts/TERMS.ar.md` (section «نطاق هذه الشروط», second paragraph). It is the only change to the text |
| Publication status | **NOT AUTHORIZED** |
| Previous approved text baseline | `c937176` |
| Amended file SHA-256 | `d98b948810f645fdb38344312d6bb86c8a78f034d94c5da775f29b5ccea74f7e` (UTF-8, LF line endings, 7,026 bytes). After the commit, verify with `git show <commit>:docs/legal/drafts/TERMS.ar.md \| sha256sum`. A working copy re-checked out with CRLF (`core.autocrlf=true`) gives a different value |
| New Git baseline | PENDING UNTIL COMMIT |

## 1. Existing wording

The only reference to the Privacy Policy, or to any other legal page, in the Terms text is in **section «نطاق هذه الشروط», second paragraph (line 16)**:

> لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية؛ إذ تُنظَّم معالجة البيانات في سياسة الخصوصية.

The text has no other mention of «سياسة الخصوصية», «حماية البيانات», `/privacy` or `/data-protection`, and no link (paragraphs render as plain text).

**Issues with the current sentence:**
1. **Availability:** it presents the Privacy Policy as an existing, available document, but `/privacy` is `PENDING_APPROVAL` (NEEDS_DECISION).
2. **«تُنظَّم» (is governed/regulated):** this presents the policy as the instrument that governs processing. The legal basis for processing is undecided (D3), and the policy draft itself defers it (R-05).
3. **«إذ» (because):** it ties the no-consent statement to the policy's existence. The statement that the Terms contain no consent must hold on its own, whether or not the policy is published.

## 2. Assessment of the illustrative wording

«وتسري أحكام سياسة الخصوصية عند نشرها وإتاحتها عبر المنصة.»

«تسري أحكام» (the provisions take effect) is **not recommended**. It implies:
- **a legal-effect regime:** the provisions acquire legal force on publication, which is a legal characterisation the Terms should not make while D3 (legal basis, applicable law) is pending;
- **a gap before publication:** no rules govern the processing that already takes place.

Describing the policy as a document that **explains** processing, and that **is made available** on publication, avoids both implications.

## 3. Options

**Option A — minimal amendment.** Only the qualifier is added; everything else is kept:

> لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية؛ إذ تُنظَّم معالجة البيانات في سياسة الخصوصية **عند نشرها وإتاحتها عبر المنصة**.

**Option B — explicit clarification.** The no-consent statement stands alone; the policy is described as a separate document that explains processing:

> لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية. **وتُبيِّن سياسة الخصوصية، وهي وثيقة مستقلة عن هذه الشروط، كيفية معالجة البيانات الشخصية، وتُتاح عبر المنصة عند نشرها.**

## 4. Side-by-side comparison

| Criterion | Current (`c937176`) | Option A | Option B |
|---|---|---|---|
| Implies `/privacy` is published or available | Yes | No | No |
| Describes the policy as *governing* processing («تُنظَّم») | Yes | Yes | No («تُبيِّن»: explains) |
| Implies no rules apply until publication | — | Yes, partly («تُنظَّم … عند نشرها») | No |
| No-consent statement independent of the policy | No («إذ») | No («إذ») | Yes (separate sentence) |
| Creates a legal basis or consent | No | No | No |
| Implies acceptance (D7) | No | No | No |
| Changes user rights or capabilities | No | No | No |
| Stays accurate after `/privacy` is published | — | Yes | Yes |
| Size of change | — | 4 words added | Second clause rewritten (one sentence) |
| Readability for household users | Good | Good, but the causal link reads oddly before publication | Good: two short statements |

## 5. Implications

**Editorial:**
- **Option A** is the smallest change, but it keeps «إذ … تُنظَّم». The resulting sentence says processing is governed by the policy *only when it is published*. That reads as if processing is currently unregulated.
- **Option B** changes one clause. It reads naturally and separates the two ideas: no consent; where processing is explained.

**Substantive:**
- **Neither option:**
  - creates a legal basis, consent or acceptance;
  - changes a right or a capability;
  - states when `/privacy` will be published.
- **Option B** also removes the characterisation of the policy as the governing instrument. That position is more accurate while D3 is pending, and is consistent with the privacy draft's own statement that the legal basis will be set later.

## 6. Recommendation

**Option B.** It meets the approved direction completely:
- it makes no availability claim;
- it makes no governing or legal-effect claim;
- the no-consent statement does not depend on the policy existing.

It is the most accurate wording for the current state (D3 pending, `/privacy` unpublished). Option A satisfies the availability point only, and introduces the "unregulated until published" reading.

## 7. Impact on other Terms clauses

- **No other clause references the Privacy Policy**, so no consequential change is needed.
- The sections «تحديث هذه الشروط» (P-08) and «التواصل» are unaffected.
- The other language-review findings (P-01, P-03 … P-11) are independent of this one.
- **Privacy draft:** `PRIVACY.ar.md` is unaffected.
- **Publication order:** with either option, `/terms` may be published before `/privacy` without a misleading reference. Once `/privacy` is published, the wording stays accurate. Removing «عند نشرها» later would be a further text change needing approval; it is optional.

## 8. Exact proposed replacement (Option B)

**Location:** `docs/legal/drafts/TERMS.ar.md`, section «نطاق هذه الشروط», second paragraph.

| | Text |
|---|---|
| Replace | لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية؛ إذ تُنظَّم معالجة البيانات في سياسة الخصوصية. |
| With | لا تتضمن هذه الشروط موافقةً على معالجة البيانات الشخصية. وتُبيِّن سياسة الخصوصية، وهي وثيقة مستقلة عن هذه الشروط، كيفية معالجة البيانات الشخصية، وتُتاح عبر المنصة عند نشرها. |

The paragraph stays one line, and therefore one published paragraph. No other line changes.

## 9. Approval required before application

1. The Executive Director, PCDCD, approves the **exact** wording (Option B as above, Option A, or an amended form). The approval is recorded with its date.
2. Only then is the replacement applied to `TERMS.ar.md`, in a separate change.
3. The resulting commit becomes the **new approved text baseline**, recorded in `docs/legal/approvals/TERMS-APPROVAL-RECORD.md`. That baseline replaces `c937176` for publication purposes.
4. The independent Arabic review examines the new baseline (or this proposal as part of it).

Until then, `TERMS.ar.md` stays at `c937176`, and publication stays **NOT AUTHORIZED**.
