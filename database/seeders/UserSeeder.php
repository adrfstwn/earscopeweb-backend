<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ambil data admin dari .env
        $adminUsername = env('ADMIN_USERNAME');
        $adminPassword = env('ADMIN_PASSWORD');
        $adminEmail = env('ADMIN_EMAIL');
        $adminKodeAkses = env('ADMIN_KODE_AKSES');

        // Cek apakah role 'admin' sudah ada
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Buat user admin jika belum ada
        $adminUser = User::firstOrCreate(
            ['email' => $adminEmail], // Cari berdasarkan email
            [
                'kode_akses' => $adminKodeAkses,
                'name' => $adminUsername,
                'email' => $adminEmail,
                'tanggal_lahir' => '2000-01-01', // Tanggal lahir default
                'gender' => 'laki-laki',
                'no_telp' => '081234567890', // Nomor telepon default
                'usia' => 25, // Usia default
                'password' => bcrypt($adminPassword), // Hash password
            ]
        );

        // Assign role 'admin' ke user
        if (!$adminUser->hasRole('admin')) {
            $adminUser->assignRole($adminRole);
        }

        $this->command->info('Admin user berhasil dibuat dan diberi role admin!');
    }
}