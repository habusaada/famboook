<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Issued-list metadata only: number, recipient, issuance, row count,
 * configured columns and whether sensitive data was included. The issued
 * values (snapshot_data) are never part of this resource; they are only
 * returned by the authorized list-detail and download endpoints.
 */
class AssistanceBeneficiaryListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'list_number' => $this->list_number,
            'recipient_organization' => $this->recipient_organization,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'issued_by' => $this->issuer ? ['name' => $this->issuer->name] : null,
            'notes' => $this->notes,
            'row_count' => $this->row_count,
            'contains_sensitive' => $this->contains_sensitive,
            'columns' => collect($this->configuration_snapshot)->map(fn ($c) => [
                'field_key' => $c['field_key'],
                'column_label' => $c['column_label'],
                'classification' => $c['classification'],
            ])->values(),
        ];
    }
}
