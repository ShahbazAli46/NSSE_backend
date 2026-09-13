<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StaffMember;
use App\Models\User;

class StaffAndFacultySeeder extends Seeder
{
    /**
     * Run the database seeds for leadership and administration staff.
     */
    public function run(): void
    {
        // 1. Principal / Executive Head
        $principalUser = User::where('role', 'principal')->first();
        StaffMember::updateOrCreate(
            ['name' => 'Ahsan Saab'],
            [
                'user_id' => $principalUser ? $principalUser->id : null,
                'designation' => 'Principal / Head of Institution',
                'father_name' => 'Haji Muhammad Ullah',
                'cnic' => '35102-1234567-9',
                'contact_number' => '0300-8889991',
                'emergency_number' => '0301-7778881',
                'address' => 'Principal Residence, NSSE Campus',
                'city' => 'Chunian',
                'date_of_joining' => '2024-01-01',
                'salary' => 120000.00,
                'payment_method' => 'bank',
                'bank_name' => 'Habib Bank Limited (HBL)',
                'account_title' => 'Ahsan Saab',
                'account_number' => 'PK44HABB00099881122334',
                'status' => 'active',
            ]
        );

        // 2. Human Resource (HR) Officer
        $hrUser = User::where('role', 'hr')->first();
        StaffMember::updateOrCreate(
            ['name' => 'Tariq HR Officer'],
            [
                'user_id' => $hrUser ? $hrUser->id : null,
                'designation' => 'Human Resource (HR) Officer',
                'father_name' => 'Muhammad Siddique',
                'cnic' => '35102-2345678-5',
                'contact_number' => '0300-5556662',
                'emergency_number' => '0301-4445552',
                'address' => 'Staff Block B, Chunian',
                'city' => 'Chunian',
                'date_of_joining' => '2024-03-01',
                'salary' => 65000.00,
                'payment_method' => 'bank',
                'bank_name' => 'Meezan Bank Ltd',
                'account_title' => 'Tariq HR Officer',
                'account_number' => 'PK12MEZN00033445566778',
                'status' => 'active',
            ]
        );

        // 3. Senior Accountant / Finance Manager
        $accUser = User::where('role', 'hr_accountant')->first();
        StaffMember::updateOrCreate(
            ['name' => 'Talib Hussain'],
            [
                'user_id' => $accUser ? $accUser->id : null,
                'designation' => 'Senior Accountant & Finance Manager',
                'father_name' => 'Chaudhry Ghulam Rasool',
                'cnic' => '35102-3456789-3',
                'contact_number' => '0300-4443331',
                'emergency_number' => '0301-2221113',
                'address' => 'Officers Colony, Chunian',
                'city' => 'Chunian',
                'date_of_joining' => '2024-02-15',
                'salary' => 70000.00,
                'payment_method' => 'bank',
                'bank_name' => 'Bank of Punjab (BOP)',
                'account_title' => 'Talib Hussain',
                'account_number' => 'PK88BOPB00077665544332',
                'status' => 'active',
            ]
        );
    }
}
