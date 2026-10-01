<?php

namespace App\Actions;

use App\Enums\FamilyKeyDecision;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportMode;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\ImportBatch;
use App\Models\ImportFamilyKeyResolution;
use App\Models\User;
use App\Support\BusinessIdentifier;
use App\Support\Import\InitialFamilyRow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import Wizard step 4 — family-key resolution (docs/03 §96a).
 *
 * One explicit administrator decision per (batch, EXACT source key), applying
 * to every staged row of the batch with that key:
 *
 *   MATCH_EXISTING_BRANCH  a selectable Branch of the batch's Clan
 *   CREATE_NEW_BRANCH      creates ONE ungrouped Branch in the batch's Clan
 *                          (existing ManageClanStructureAction; needs
 *                          clan.manage) and maps the key to it
 *   SAME_BRANCH_AS_KEY     reuses the FINAL Branch of another resolved key of
 *                          the same batch (stored directly: no chains, no loops)
 *   NO_BRANCH              explicitly no Branch (families may later get
 *                          branch_id = NULL)
 *
 * No decision = UNRESOLVED. The staged source key is never rewritten, nothing
 * is inferred (names, frequency, spelling), nothing is copied from other
 * batches, and no Family, Person, Membership or Branch Group is created.
 * Changing or clearing a decision never deletes a Branch it had created:
 * Branches are Clan master data from the moment they are created.
 * Every mutation locks the batch row, so concurrent decisions serialize.
 *
 * autoCreateBranches() is ONE explicit administrator decision for every
 * still-unresolved key of an INITIAL batch (the workbook is the source of
 * the Clan's Branches): an existing Branch of the Clan with exactly the same
 * normalized name is reused (MATCH_EXISTING_BRANCH), otherwise one ungrouped
 * Branch is created per key with the permanent code BR_ + its reserved id.
 */
class ResolveFamilyKeyAction
{
    private const CODE = '/^[A-Z][A-Z0-9_]*$/';

    public function __construct(private readonly ManageClanStructureAction $clans) {}

    /**
     * @param  array{branch_id?: ?string, branch?: array{name?: ?string, code?: ?string}, same_as_key?: ?string}  $data
     */
    public function handle(ImportBatch $batch, string $key, FamilyKeyDecision $decision, array $data, User $user): ImportFamilyKeyResolution
    {
        return DB::transaction(function () use ($batch, $key, $decision, $data, $user) {
            $batch = $this->lockResolvable($batch);
            $this->assertStagedKeys($batch, [$key]);

            [$branchId, $reference] = match ($decision) {
                FamilyKeyDecision::MATCH_EXISTING_BRANCH => [$this->selectableBranch($batch, $data['branch_id'] ?? null)->id, null],
                FamilyKeyDecision::CREATE_NEW_BRANCH => [$this->createBranches($batch, [[
                    'source_family_key' => $key,
                    'name' => $data['branch']['name'] ?? null,
                    'code' => $data['branch']['code'] ?? null,
                ]], $user)[$key], null],
                FamilyKeyDecision::SAME_BRANCH_AS_KEY => $this->sameBranchAs($batch, $key, $data['same_as_key'] ?? null),
                FamilyKeyDecision::NO_BRANCH => [null, null],
            };

            return $this->save($batch, $key, $decision, $branchId, $reference, $user);
        });
    }

    /** Back to UNRESOLVED (the decision record is removed; Branches stay). */
    public function clear(ImportBatch $batch, string $key): void
    {
        DB::transaction(function () use ($batch, $key) {
            $batch = $this->lockResolvable($batch);
            ImportFamilyKeyResolution::where('import_batch_id', $batch->id)->where('source_family_key', $key)->delete();
        });
    }

    /**
     * Explicitly selected keys → one new ungrouped Branch each, all or nothing.
     *
     * @param  list<array{source_family_key: string, name: ?string, code: ?string}>  $items
     */
    public function bulkCreate(ImportBatch $batch, array $items, User $user): int
    {
        return DB::transaction(function () use ($batch, $items, $user) {
            $batch = $this->lockResolvable($batch);
            $keys = array_column($items, 'source_family_key');
            $this->assertStagedKeys($batch, $keys);
            foreach ($this->createBranches($batch, $items, $user) as $key => $branchId) {
                $this->save($batch, $key, FamilyKeyDecision::CREATE_NEW_BRANCH, $branchId, null, $user);
            }

            return count($items);
        });
    }

    /**
     * Every unresolved key of an INITIAL batch → its Branch, all or nothing,
     * in first-appearance (row number) order. An existing Branch of the Clan
     * whose normalized name equals the key exactly is reused; anything else
     * gets a new ungrouped Branch named after the key, code BR_000123 from
     * the reserved Branch id. No fuzzy matching, no spelling normalization;
     * an exact match that is not selectable, or matches several Branches,
     * stops the whole request (never a guess, never a duplicate). Already
     * resolved keys are untouched, so a re-run is a no-op.
     *
     * @return array{created: int, matched: int}
     */
    public function autoCreateBranches(ImportBatch $batch, User $user): array
    {
        if (! $user->can('clan.manage')) {
            throw new AuthorizationException('إنشاء الفروع يتطلب صلاحية إدارة العشائر.');
        }

        return DB::transaction(function () use ($batch, $user) {
            $batch = $this->lockResolvable($batch);
            if ($batch->import_mode !== ImportMode::INITIAL) {
                throw ValidationException::withMessages(['batch' => 'إنشاء الفروع تلقائيًا متاح للاستيراد الأولي فقط.']);
            }
            /** @var Clan $clan */
            $clan = Clan::findOrFail($batch->clan_id);
            if (! $clan->is_active) {
                throw ValidationException::withMessages(['batch' => 'العشيرة المستهدفة غير مفعّلة.']);
            }

            $resolved = ImportFamilyKeyResolution::where('import_batch_id', $batch->id)->pluck('source_family_key')->all();
            $keys = DB::table('import_rows')
                ->where('import_batch_id', $batch->id)
                ->whereNotNull('source_family_key')
                ->whereNotIn('source_family_key', $resolved ?: [''])
                ->groupBy('source_family_key')
                ->selectRaw('source_family_key, min(row_number) as first_row')
                ->orderBy('first_row')->orderBy('source_family_key')
                ->pluck('source_family_key')->all();

            $byName = [];
            foreach (Branch::where('clan_id', $clan->id)->get() as $branch) {
                $byName[(string) InitialFamilyRow::normalizeKey($branch->name)][] = $branch;
            }

            $created = 0;
            $matched = 0;
            foreach ($keys as $key) {
                $existing = $byName[$key] ?? [];
                if (count($existing) > 1) {
                    throw ValidationException::withMessages(['batch' => "يوجد أكثر من فرع باسم «{$key}» في هذه العشيرة؛ احسم هذا المفتاح يدويًا."]);
                }
                if (count($existing) === 1) {
                    if (! $existing[0]->isSelectable()) {
                        throw ValidationException::withMessages(['batch' => "الفرع «{$key}» موجود لكنه غير مفعّل؛ فعّله أو احسم هذا المفتاح يدويًا."]);
                    }
                    $this->save($batch, $key, FamilyKeyDecision::MATCH_EXISTING_BRANCH, $existing[0]->id, null, $user);
                    $matched++;

                    continue;
                }

                $id = BusinessIdentifier::nextId('branches');
                try {
                    $branch = $this->clans->createBranch($clan, null, [
                        'id' => $id,
                        'code' => BusinessIdentifier::format('BR', $id, '_'),
                        'name' => $key,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['batch' => 'تعذّر إنشاء رمز فرع فريد؛ لم يُنشأ أي فرع.']);
                }
                $byName[$key] = [$branch];
                $this->save($batch, $key, FamilyKeyDecision::CREATE_NEW_BRANCH, $branch->id, null, $user);
                $created++;
            }

            return ['created' => $created, 'matched' => $matched];
        });
    }

    /** @param list<string> $keys explicitly selected keys → NO_BRANCH */
    public function bulkNoBranch(ImportBatch $batch, array $keys, User $user): int
    {
        return DB::transaction(function () use ($batch, $keys, $user) {
            $batch = $this->lockResolvable($batch);
            $this->assertStagedKeys($batch, $keys);
            foreach (array_unique($keys) as $key) {
                $this->save($batch, $key, FamilyKeyDecision::NO_BRANCH, null, null, $user);
            }

            return count(array_unique($keys));
        });
    }

    private function lockResolvable(ImportBatch $batch): ImportBatch
    {
        /** @var ImportBatch $batch */
        $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        if ($batch->status !== ImportBatchStatus::READY_FOR_REVIEW || $batch->mapping_confirmed_at === null) {
            throw ValidationException::withMessages(['batch' => 'يمكن حسم مفاتيح الأسر بعد تجهيز الصفوف وقبل الاعتماد فقط.']);
        }

        return $batch;
    }

    /** @param list<string> $keys */
    private function assertStagedKeys(ImportBatch $batch, array $keys): void
    {
        if ($keys === []) {
            throw ValidationException::withMessages(['source_family_key' => 'لم يُحدَّد أي مفتاح.']);
        }
        $staged = DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->whereIn('source_family_key', array_unique($keys))
            ->distinct()->pluck('source_family_key')->all();
        if (count($staged) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['source_family_key' => 'المفتاح غير موجود في صفوف هذه الدفعة.']);
        }
    }

    /** A Branch of the batch's Clan that is selectable now (Branch::isSelectable). */
    private function selectableBranch(ImportBatch $batch, ?string $uuid): Branch
    {
        $branch = $uuid ? Branch::where('clan_id', $batch->clan_id)->where('uuid', $uuid)->first() : null;
        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'الفرع غير موجود في العشيرة المستهدفة لهذه الدفعة.']);
        }
        if (! $branch->isSelectable()) {
            throw ValidationException::withMessages(['branch_id' => 'الفرع غير مفعّل ولا يمكن اختياره.']);
        }

        return $branch;
    }

    /** @return array{0: int, 1: string} final Branch id + reference key */
    private function sameBranchAs(ImportBatch $batch, string $key, ?string $other): array
    {
        if ($other === null || $other === $key) {
            throw ValidationException::withMessages(['same_as_key' => 'اختر مفتاحًا آخر محسومًا بفرع.']);
        }
        $source = ImportFamilyKeyResolution::where('import_batch_id', $batch->id)->where('source_family_key', $other)->first();
        if ($source === null || $source->branch_id === null) {
            throw ValidationException::withMessages(['same_as_key' => 'المفتاح الآخر غير محسوم بفرع بعد.']);
        }
        $branch = Branch::findOrFail($source->branch_id);

        return [$this->selectableBranch($batch, $branch->uuid)->id, $other];
    }

    /**
     * Creates one ungrouped Branch per item in the batch's Clan (all or nothing).
     *
     * @param  list<array{source_family_key: string, name: ?string, code: ?string}>  $items
     * @return array<string, int> source key => new Branch id
     */
    private function createBranches(ImportBatch $batch, array $items, User $user): array
    {
        if (! $user->can('clan.manage')) {
            throw new AuthorizationException('إنشاء الفروع يتطلب صلاحية إدارة العشائر.');
        }
        /** @var Clan $clan */
        $clan = Clan::findOrFail($batch->clan_id);
        if (! $clan->is_active) {
            throw ValidationException::withMessages(['branch' => 'العشيرة المستهدفة غير مفعّلة.']);
        }

        $existingNames = Branch::where('clan_id', $clan->id)->pluck('name')->map(fn ($n) => InitialFamilyRow::normalizeKey($n))->all();
        $existingCodes = Branch::where('clan_id', $clan->id)->pluck('code')->all();
        $errors = [];
        $seenNames = [];
        $seenCodes = [];
        foreach ($items as $i => $item) {
            $name = InitialFamilyRow::normalizeKey($item['name'] ?? null);
            $code = trim((string) ($item['code'] ?? ''));
            if ($name === null || mb_strlen($name) > 150) {
                $errors["items.{$i}.name"] = 'اسم الفرع مطلوب (حتى 150 حرفًا).';
            } elseif (in_array($name, $existingNames, true) || isset($seenNames[$name])) {
                $errors["items.{$i}.name"] = "يوجد فرع باسم «{$name}» في هذه العشيرة؛ اربط المفتاح به بدل إنشاء فرع مكرر.";
            }
            if ($code === '' || mb_strlen($code) > 50 || ! preg_match(self::CODE, $code)) {
                $errors["items.{$i}.code"] = 'الرمز يجب أن يبدأ بحرف إنجليزي كبير ويحتوي على أحرف كبيرة وأرقام و _ فقط.';
            } elseif (in_array($code, $existingCodes, true) || isset($seenCodes[$code])) {
                $errors["items.{$i}.code"] = 'هذا الرمز مستخدم مسبقًا في هذه العشيرة.';
            }
            $seenNames[(string) $name] = true;
            $seenCodes[$code] = true;
        }
        if ($errors !== []) {
            // A single-key request reports on "branch.*".
            if (count($items) === 1) {
                $errors = collect($errors)->mapWithKeys(fn ($m, $k) => [str_replace('items.0.', 'branch.', $k) => $m])->all();
            }
            throw ValidationException::withMessages($errors);
        }

        $created = [];
        try {
            foreach ($items as $item) {
                // Existing domain logic; ungrouped (no Branch Group is created).
                $branch = $this->clans->createBranch($clan, null, [
                    'code' => trim($item['code']),
                    'name' => InitialFamilyRow::normalizeKey($item['name']),
                ]);
                $created[$item['source_family_key']] = $branch->id;
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['branch.code' => 'هذا الرمز مستخدم مسبقًا في هذه العشيرة.']);
        }

        return $created;
    }

    private function save(ImportBatch $batch, string $key, FamilyKeyDecision $decision, ?int $branchId, ?string $reference, User $user): ImportFamilyKeyResolution
    {
        return ImportFamilyKeyResolution::updateOrCreate(
            ['import_batch_id' => $batch->id, 'source_family_key' => $key],
            [
                'clan_id' => $batch->clan_id,
                'decision' => $decision,
                'branch_id' => $branchId,
                'reference_source_key' => $reference,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ]
        );
    }
}
