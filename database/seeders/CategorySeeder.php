<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Maintenance',
                'icon' => '🛠️',
                'description' => 'Building repairs, plumbing, HVAC, civil maintenance, and campus electrical work.',
            ],
            [
                'name' => 'Utilities & Bills',
                'icon' => '💡',
                'description' => 'WAPDA electricity bills, sui gas, water supply, and high-speed campus internet.',
            ],
            [
                'name' => 'Academic Supplies',
                'icon' => '📚',
                'description' => 'Whiteboard markers, printed exam papers, registers, student notebooks, and library books.',
            ],
            [
                'name' => 'Events & Protocol',
                'icon' => '🎉',
                'description' => 'Sports gala, annual prize distribution ceremonies, VIP guest protocol, and refreshment arrangements.',
            ],
            [
                'name' => 'Procurement & Assets',
                'icon' => '📦',
                'description' => 'Classroom furniture, administrative desks, science equipment, and hardware inventory purchases.',
            ],
            [
                'name' => 'Salaries & Allowances',
                'icon' => '💵',
                'description' => 'Contractual staff stipends, overtime payouts, and faculty honorariums.',
            ],
            [
                'name' => 'Transport & Fleet Fuel',
                'icon' => '🚌',
                'description' => 'School bus diesel/petrol, generator fuel, routine vehicle servicing, and vehicle fitness tests.',
            ],
            [
                'name' => 'Security & Surveillance',
                'icon' => '🛡️',
                'description' => 'CCTV cameras maintenance, security guard uniforms, walkie-talkie sets, and metal detector maintenance.',
            ],
            [
                'name' => 'IT & Lab Equipment',
                'icon' => '💻',
                'description' => 'Computer lab hardware, printer toner refills, networking switches, and software licensing.',
            ],
            [
                'name' => 'Miscellaneous',
                'icon' => '📑',
                'description' => 'Emergency petty cash expenses and general administrative out-of-pocket costs.',
            ],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['name' => $cat['name']],
                [
                    'slug' => Str::slug($cat['name']),
                    'icon' => $cat['icon'],
                    'description' => $cat['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
