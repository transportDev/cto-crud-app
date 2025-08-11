<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TableBuilderService
{
    /**
     * Build a normalized definition array and log a preview.
     * IMPORTANT: Does NOT execute schema changes yet.
     */
    public function preview(array $input): array
    {
        $definition = [
            'table' => $input['table'],
            'timestamps' => (bool)($input['timestamps'] ?? false),
            'columns' => collect($input['columns'] ?? [])
                ->map(function ($col) {
                    return [
                        'name' => $col['name'],
                        'type' => $col['type'],
                        'length' => $col['length'] ?? null,
                        'precision' => $col['precision'] ?? null,
                        'scale' => $col['scale'] ?? null,
                        'nullable' => (bool)($col['nullable'] ?? false),
                        'default' => $col['default'] ?? null,
                        'unique' => (bool)($col['unique'] ?? false),
                        'index' => $col['index'] ?? null,
                    ];
                })->all(),
        ];

        Log::info('TableBuilder preview', $definition);

        return $definition;
    }

    /**
     * TODO: Implement transactional table creation safely using Schema::create().
     * - Validate names, avoid reserved words, prevent collisions.
     * - Use Doctrine DBAL for advanced introspection as needed.
     * - Wrap in a transaction and rollback on error.
     */
    public function create(array $definition): void
    {
        // TODO: Implement guarded, transactional create.
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
    }

    public static function isReserved(string $name): bool
    {
        $reserved = [
            'select','insert','update','delete','table','from','where','and','or','join','group','order','by',
            'create','alter','drop','index','constraint','key','primary','unique','int','varchar','text',
        ];
        return in_array(strtolower($name), $reserved, true);
    }
}
