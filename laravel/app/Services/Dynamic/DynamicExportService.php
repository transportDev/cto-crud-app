<?php

namespace App\Services\Dynamic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DynamicExportService
 *
 * Streams CSV exports safely with escaped fields and chunked iteration.
 */
class DynamicExportService
{
    public function __construct(
        protected DynamicSchemaService $schema
    ) {}

    public function streamCsv(?string $table)
    {
        $table = $this->schema->sanitizeTable($table);
        if (!$table) {
            abort(400, 'Invalid table');
        }

        $columns = Schema::getColumnListing($table);
        $pk = $this->schema->primaryKey($table);

        return response()->streamDownload(function () use ($table, $columns, $pk) {
            $out = fopen('php://output', 'w');
            // Force UTF-8 BOM optional? Keep simple, write header only
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
                            // fputcsv will escape properly, just ensure scalar string
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
