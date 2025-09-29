<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
            $table->string('email')->nullable()->change();
        });

        $existingUsernames = DB::table('users')->pluck('username')->filter()->map(fn($name) => Str::lower((string) $name))->all();
        $usedUsernames = collect($existingUsernames)->all();

        DB::table('users')
            ->select(['id', 'name', 'email', 'username'])
            ->orderBy('id')
            ->lazy()
            ->each(function ($user) use (&$usedUsernames) {
                if (filled($user->username)) {
                    $usedUsernames[] = Str::lower((string) $user->username);
                    return;
                }

                $base = Str::slug((string) $user->name, '_');

                if (blank($base) && filled($user->email)) {
                    $base = Str::slug(Str::before((string) $user->email, '@'), '_');
                }

                if (blank($base)) {
                    $base = 'pengguna';
                }

                $candidate = Str::lower($base);
                $suffix = 1;

                while (in_array($candidate, $usedUsernames, true)) {
                    $candidate = Str::lower($base . '_' . $suffix);
                    $suffix++;
                }

                DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
                $usedUsernames[] = $candidate;
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'username')) {
                $table->dropUnique('users_username_unique');
                $table->dropColumn('username');
            }

            $table->string('email')->nullable(false)->change();
        });
    }
};
