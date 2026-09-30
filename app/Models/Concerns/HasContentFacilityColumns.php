<?php

declare(strict_types=1);

namespace App\Models\Concerns;

trait HasContentFacilityColumns
{
    public function initializeHasContentFacilityColumns(): void
    {
        $this->fillable = array_values(array_unique(array_merge($this->fillable, [
            'facility_code',
            'facility_group_code',
            'sort_order',
            'number_value',
            'ind_logic',
            'ind_fee',
            'ind_yes_or_no',
            'voucher',
            'distance',
            'amount',
            'currency',
            'application_type',
            'time_from',
            'time_to',
            'date_to',
        ])));

        $this->mergeCasts([
            'facility_code' => 'integer',
            'facility_group_code' => 'integer',
            'sort_order' => 'integer',
            'number_value' => 'integer',
            'ind_logic' => 'boolean',
            'ind_fee' => 'boolean',
            'ind_yes_or_no' => 'boolean',
            'voucher' => 'boolean',
            'distance' => 'integer',
            'amount' => 'decimal:2',
            'date_to' => 'date',
        ]);
    }
}
