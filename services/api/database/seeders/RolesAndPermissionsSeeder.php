<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::ALL as $slug) {
            Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::title(str_replace('.', ' ', $slug))],
            );
        }

        $admin = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrator']);
        $admin->permissions()->syncWithoutDetaching(Permission::pluck('id'));

        $subscriber = Role::firstOrCreate(['slug' => Role::SUBSCRIBER], ['name' => 'Subscriber']);
        $subscriber->permissions()->detach();
    }
}
