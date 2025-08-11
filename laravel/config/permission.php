<?php

return [
    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role' => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
        // 'role_pivot_key' => 'role_id',
        // 'permission_pivot_key' => 'permission_id',
    ],

    'register_permission_check_method' => true,

    'teams' => false,
    'team_model' => null,

    'enable_wildcard_permission' => false,

    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,

    'use_uuid' => false,
    'uuid_column_names' => [
        'permissions' => 'uuid',
        'roles' => 'uuid',
    ],

    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];