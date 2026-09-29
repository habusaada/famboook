<?php

namespace App\Enums;

// docs/03 §96a — the explicit purpose of an import batch; never inferred.
// INITIAL: first controlled population of a Clan's dataset.
// INCREMENTAL: a later (possibly daily) workbook that may repeat existing
// records, add new ones, change some and contain duplicates/conflicts. It is
// NOT a full synchronization: absence from a file never deletes anything.
enum ImportMode: string
{
    case INITIAL = 'INITIAL';
    case INCREMENTAL = 'INCREMENTAL';
}
