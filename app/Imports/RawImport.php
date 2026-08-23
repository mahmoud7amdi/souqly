<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Reads a sheet into a plain array so the controller can validate every row
 * before anything is written.
 */
class RawImport implements ToArray
{
    /**
     * @param  array<int, array<int, mixed>>  $array
     * @return array<int, array<int, mixed>>
     */
    public function array(array $array): array
    {
        return $array;
    }
}
