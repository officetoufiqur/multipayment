<?php

namespace App\Helpers;

use Carbon\Carbon;

class GenerateNumber
{
    public static function generate(
        string $model,
        string $column = 'invoice_no',
        string $prefix = '',
        int $padding = 3
    ): string {
        $year = Carbon::now()->year;

        $query = $model::query();

        if ($prefix) {
            $query->where($column, 'like', "{$prefix}-{$year}-%");
        } else {
            $query->where($column, 'like', "{$year}-%");
        }

        $lastRecord = $query
            ->latest('id')
            ->first();

        if ($lastRecord && $lastRecord->{$column}) {
            $parts = explode('-', $lastRecord->{$column});
            $lastNumber = (int) end($parts);
            $nextNumber = str_pad($lastNumber + 1, $padding, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = str_pad(1, $padding, '0', STR_PAD_LEFT);
        }

        return $prefix
            ? "{$prefix}-{$year}-{$nextNumber}"
            : "{$year}-{$nextNumber}";
    }
}
