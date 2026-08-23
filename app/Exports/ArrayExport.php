<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;

/**
 * Generic exporter for any pre-built rows array.
 */
class ArrayExport implements FromArray
{
    /**
     * @param  array<int, array<int|string, mixed>>  $rows
     */
    public function __construct(private array $rows) {}

    /**
     * @return array<int, array<int|string, mixed>>
     */
    public function array(): array
    {
        return $this->rows;
    }
}
