<?php

namespace App\Services\Dynamic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service untuk menangani ekspor data tabel secara dinamis ke format CSV.
 *
 * Service ini menyediakan fungsionalitas untuk mengekspor data dari tabel database
 * ke file CSV dengan menggunakan streaming untuk efisiensi memori. Data diproses
 * dalam chunk untuk menghindari memory exhaustion pada dataset besar.
 *
 * @package App\Services\Dynamic
 */
class DynamicExportService
{
    /**
     * Membuat instance service dengan dependency injection.
     *
     * @param DynamicSchemaService $schema Service untuk menangani operasi skema database
     */
    public function __construct(
        protected DynamicSchemaService $schema
    ) {}

    /**
     * Membuat streaming response untuk ekspor CSV dari tabel yang ditentukan.
     *
     * Method ini akan:
     * - Memvalidasi dan sanitasi nama tabel
     * - Mengambil daftar kolom dari tabel
     * - Membuat file CSV dengan header kolom
     * - Melakukan streaming data secara bertahap (chunked)
     * - Menangani nilai array/object dengan JSON encoding
     *
     * @param string|null $table Nama tabel yang akan diekspor
     * 
     * @return StreamedResponse Response dengan streaming CSV
     * 
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     *         Jika nama tabel tidak valid atau tabel tidak ditemukan
     */
    public function streamCsv(?string $table): StreamedResponse
    {
        $table = $this->schema->sanitizeTable($table);
        if (!$table) {
            abort(400, 'Invalid table');
        }

        $columns = Schema::getColumnListing($table);
        $pk = $this->schema->primaryKey($table);

        return response()->streamDownload(function () use ($table, $columns, $pk) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $columns);

            DB::table($table)
                ->orderBy($pk)
                ->chunk((int)config('cto.export_chunk', 500), function ($rows) use ($out, $columns) {
                    foreach ($rows as $row) {
                        $data = [];
                        foreach ($columns as $col) {
                            $val = $row->{$col} ?? null;
                            if (is_array($val) || is_object($val)) {
                                $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                            $data[] = $val;
                        }
                        fputcsv($out, $data);
                    }
                });

            fclose($out);
        }, $table . '-' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
