<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Grant;
use App\Models\Cheque;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Carbon;

class MockDataSeeder extends Seeder
{
    public function run(): void
    {
        $accountant = User::where('role', 'hr_accountant')->first() ?? User::first();
        $userId = $accountant ? $accountant->id : 1;

        // 1. Government Grants (Income Inflow)
        $grant1 = Grant::create([
            'title' => 'Q1 Operational & Maintenance Grant 2026-27',
            'amount' => 2500000.00,
            'deposit_date' => '2026-08-01',
            'bank_account' => 'HBL - School Main Account (0192-882190)',
            'reference_no' => 'SED-PUNJAB-Q1-88910',
            'notes' => 'First quarterly recurrent budget tranche received from Punjab School Education Department.',
            'created_by' => $userId,
        ]);

        $grant2 = Grant::create([
            'title' => 'Special Development & IT Labs Upgrade Tranche',
            'amount' => 1200000.00,
            'deposit_date' => '2026-08-15',
            'bank_account' => 'HBL - School Main Account (0192-882190)',
            'reference_no' => 'PUNJAB-DEV-TR-44102',
            'notes' => 'Special infrastructure allocation for STEM robotics and science laboratory modernisation.',
            'created_by' => $userId,
        ]);

        // 2. Cheques Register
        $chq1 = Cheque::create([
            'cheque_no' => 'CHQ-890121',
            'amount' => 50000.00,
            'amount_tier' => 4,
            'tier_color' => 'red',
            'issue_date' => '2026-08-02',
            'cashed_date' => '2026-08-02',
            'status' => 'cashed',
            'beneficiary' => 'Self (Petty Cash Drawer Replenishment #1)',
            'purpose' => 'petty_cash',
            'description' => 'Cash withdrawal from HBL for initial daily school petty cash expenses.',
            'created_by' => $userId,
        ]);

        $chq2 = Cheque::create([
            'cheque_no' => 'CHQ-890122',
            'amount' => 45000.00,
            'amount_tier' => 3,
            'tier_color' => 'orange',
            'issue_date' => '2026-08-05',
            'cashed_date' => '2026-08-05',
            'status' => 'cashed',
            'beneficiary' => 'Alpha Stationery & Printing Press',
            'purpose' => 'vendor_payment',
            'description' => 'Direct cheque payment for 5,000 official examination answer sheet booklets.',
            'created_by' => $userId,
        ]);

        $chq3 = Cheque::create([
            'cheque_no' => 'CHQ-890123',
            'amount' => 185000.00,
            'amount_tier' => 4,
            'tier_color' => 'red',
            'issue_date' => '2026-08-10',
            'cashed_date' => '2026-08-10',
            'status' => 'cashed',
            'beneficiary' => 'LESCO (Chunian Sub-Division)',
            'purpose' => 'utility',
            'description' => 'Commercial high-tension electricity bill for School Main Campus for July/August billing cycle.',
            'created_by' => $userId,
        ]);

        $chq4 = Cheque::create([
            'cheque_no' => 'CHQ-890124',
            'amount' => 40000.00,
            'amount_tier' => 3,
            'tier_color' => 'orange',
            'issue_date' => '2026-08-14',
            'cashed_date' => '2026-08-14',
            'status' => 'cashed',
            'beneficiary' => 'Self (Petty Cash Drawer Replenishment #2)',
            'purpose' => 'petty_cash',
            'description' => 'Mid-month cash drawer replenishment for Independence Day and operational expenses.',
            'created_by' => $userId,
        ]);

        $chq5 = Cheque::create([
            'cheque_no' => 'CHQ-890125',
            'amount' => 36000.00,
            'amount_tier' => 3,
            'tier_color' => 'orange',
            'issue_date' => '2026-08-18',
            'cashed_date' => '2026-08-18',
            'status' => 'cashed',
            'beneficiary' => 'PTCL Optical Fiber Corporate Services',
            'purpose' => 'utility',
            'description' => 'Dedicated high-speed GPON Fiber Internet annual institutional lease line.',
            'created_by' => $userId,
        ]);

        $chq6 = Cheque::create([
            'cheque_no' => 'CHQ-890126',
            'amount' => 75000.00,
            'amount_tier' => 4,
            'tier_color' => 'red',
            'issue_date' => '2026-08-20',
            'cashed_date' => '2026-08-20',
            'status' => 'cashed',
            'beneficiary' => 'Falcon Elite Security Guards (Pvt) Ltd',
            'purpose' => 'vendor_payment',
            'description' => 'Monthly outsourced armed campus security guard deployment contract.',
            'created_by' => $userId,
        ]);

        $chq7 = Cheque::create([
            'cheque_no' => 'CHQ-890127',
            'amount' => 50000.00,
            'amount_tier' => 4,
            'tier_color' => 'red',
            'issue_date' => '2026-08-25',
            'cashed_date' => '2026-08-25',
            'status' => 'cashed',
            'beneficiary' => 'Self (Petty Cash Drawer Replenishment #3)',
            'purpose' => 'petty_cash',
            'description' => 'Petty cash drawer replenishment for pre-board exams and lab supplies.',
            'created_by' => $userId,
        ]);

        // 3. 35 Realistic Expenses across all 4 Color Tiers
        $mockExpenses = [
            // 🟢 TIER 1: Micro / Petty (< 5,000 PKR)
            [
                'title' => 'Guest Tea & Refreshments for Punjab SED Inspection Team',
                'category' => 'Hospitality',
                'amount' => 1450,
                'description' => 'Bakery snacks and green tea served during Director School Education visit.',
                'payment_method' => 'Cash',
                'date' => '2026-08-02 11:30:00',
            ],
            [
                'title' => 'Whiteboard Markers & Foam Dusters for Junior Block',
                'category' => 'Stationery',
                'amount' => 2200,
                'description' => '2 boxes of black and blue Dollar whiteboard markers and 8 magnetic dusters.',
                'payment_method' => 'Cash',
                'date' => '2026-08-03 10:15:00',
            ],
            [
                'title' => 'Electric Circuit Fuses & Insulating Tape for Admin Block',
                'category' => 'Maintenance',
                'amount' => 850,
                'description' => 'Emergency electrical replacement of burnt fuse switches in staff room distribution box.',
                'payment_method' => 'Cash',
                'date' => '2026-08-04 14:00:00',
            ],
            [
                'title' => 'Official Postage & Courier for BISE Lahore Registration Forms',
                'category' => 'Office Expense',
                'amount' => 680,
                'description' => 'TCS urgent courier dispatch of Grade 9 student registration forms to Board office.',
                'payment_method' => 'Cash',
                'date' => '2026-08-06 12:45:00',
            ],
            [
                'title' => 'Dispensary First Aid Emergency Medicine Kit Refill',
                'category' => 'Medical',
                'amount' => 2800,
                'description' => 'Purchased bandages, Burnol, Panadol syrup, Pyodine, and surgical cotton from Chunian Pharmacy.',
                'payment_method' => 'Cash',
                'date' => '2026-08-07 09:30:00',
            ],
            [
                'title' => 'Staff Room Mineral Water Cans (10 Units)',
                'category' => 'Utilities',
                'amount' => 1600,
                'description' => '19-liter drinking water cans delivery for teachers staff room and principal office.',
                'payment_method' => 'Cash',
                'date' => '2026-08-08 11:00:00',
            ],
            [
                'title' => 'Washroom Brass Bibcock Taps & Washer Replacement',
                'category' => 'Plumbing',
                'amount' => 1950,
                'description' => 'Replaced leaking taps in Boys Block ground floor washroom.',
                'payment_method' => 'Cash',
                'date' => '2026-08-09 15:20:00',
            ],
            [
                'title' => 'Lawn Mower Fuel & Garden Pruning Shears',
                'category' => 'Grounds & Garden',
                'amount' => 3400,
                'description' => '10 liters petrol for grass cutting machine and hedge pruning shears for school gardener.',
                'payment_method' => 'Cash',
                'date' => '2026-08-11 08:30:00',
            ],
            [
                'title' => 'Automatic School Bell Timer Battery Replacement',
                'category' => 'Electronics',
                'amount' => 1200,
                'description' => 'New 12V rechargeable backup battery for master bell controller unit.',
                'payment_method' => 'Cash',
                'date' => '2026-08-12 10:00:00',
            ],
            [
                'title' => 'Floor Phenyl, Detergent & Mops for Janitorial Staff',
                'category' => 'Cleaning',
                'amount' => 4500,
                'description' => 'Monthly bulk cleaning chemicals and microfiber mops for campus sweepers.',
                'payment_method' => 'Cash',
                'date' => '2026-08-13 13:10:00',
            ],
            [
                'title' => 'Independence Day National Flag Hoisting Rope & Badges',
                'category' => 'Events',
                'amount' => 950,
                'description' => 'Heavy nylon rope for flag pole and green badges for student prefects.',
                'payment_method' => 'Cash',
                'date' => '2026-08-14 07:30:00',
            ],
            [
                'title' => 'Photocopying & Spiral Binding of Monthly Syllabus Trackers',
                'category' => 'Stationery',
                'amount' => 3100,
                'description' => '35 spiral-bound curriculum log books for all teaching faculty.',
                'payment_method' => 'Cash',
                'date' => '2026-08-16 11:40:00',
            ],

            // 🔵 TIER 2: Operational (5,000 - 25,000 PKR)
            [
                'title' => 'Chemistry & Biology Lab Glassware & Reagent Refills',
                'category' => 'Lab Supplies',
                'amount' => 18500,
                'description' => 'Purchased test tubes, beakers, Litmus strips, Copper Sulfate, and dilute Hydrochloric acid.',
                'payment_method' => 'Cash',
                'date' => '2026-08-04 16:00:00',
            ],
            [
                'title' => 'Inter-House Sports Gala Trophies & Medal Engraving',
                'category' => 'Sports',
                'amount' => 22000,
                'description' => '12 engraved winner shields and 48 gold/silver/bronze medals from Kasur Sports Market.',
                'payment_method' => 'Cash',
                'date' => '2026-08-07 15:45:00',
            ],
            [
                'title' => 'School Library Urdu Literature & Reference Books Collection',
                'category' => 'Library',
                'amount' => 15000,
                'description' => 'Purchased 45 newly released historical and science books for student library.',
                'payment_method' => 'Cash',
                'date' => '2026-08-11 14:15:00',
            ],
            [
                'title' => 'Generator Diesel Fuel for Morning Assembly Power Outages',
                'category' => 'Generator Fuel',
                'amount' => 19500,
                'description' => '70 liters diesel for 25kVA school standby generator.',
                'payment_method' => 'Cash',
                'date' => '2026-08-13 09:00:00',
            ],
            [
                'title' => 'Cricket Bats, Footballs & Badminton Net Sets',
                'category' => 'Sports',
                'amount' => 14800,
                'description' => 'Purchased 4 CA tennis cricket bats, 6 tournament footballs, and 4 nylon badminton nets.',
                'payment_method' => 'Cash',
                'date' => '2026-08-15 11:20:00',
            ],
            [
                'title' => 'Computer Lab USB Keyboards, Mice & 10m HDMI Cables',
                'category' => 'IT Equipment',
                'amount' => 16200,
                'description' => 'Replaced 12 damaged keyboards and mice in Computer Lab #1.',
                'payment_method' => 'Cash',
                'date' => '2026-08-17 12:30:00',
            ],
            [
                'title' => 'HP LaserJet Pro Toner Cartridges (2 Units)',
                'category' => 'Office Expense',
                'amount' => 12500,
                'description' => 'Original high-yield black toner cartridges for examination and admin printers.',
                'payment_method' => 'Cash',
                'date' => '2026-08-19 10:45:00',
            ],
            [
                'title' => 'Drinking Water Filtration Plant 5-Stage Cartridges & UV Lamp',
                'category' => 'Maintenance',
                'amount' => 8900,
                'description' => 'Routine bi-monthly filter change for student RO clean drinking water plant.',
                'payment_method' => 'Cash',
                'date' => '2026-08-21 14:00:00',
            ],
            [
                'title' => 'Campus Pest Control & Termite Fumigation Spraying Service',
                'category' => 'Maintenance',
                'amount' => 24000,
                'description' => 'Full chemical spraying of library wooden racks, classrooms, and cafeteria.',
                'payment_method' => 'Cash',
                'date' => '2026-08-22 16:30:00',
            ],
            [
                'title' => 'Teacher Professional Development CPR & Emergency Workshop',
                'category' => 'Training',
                'amount' => 7500,
                'description' => 'Honorarium and lunch boxes for Rescue 1122 trainers.',
                'payment_method' => 'Cash',
                'date' => '2026-08-23 13:00:00',
            ],
            [
                'title' => 'Grade 9 & 10 Pre-Board Question Papers Offset Printing',
                'category' => 'Printing',
                'amount' => 21000,
                'description' => 'Confidential sealed printing of 1,200 exam paper sets for mid-term tests.',
                'payment_method' => 'Cash',
                'date' => '2026-08-24 11:15:00',
            ],
            [
                'title' => 'Staff Biometric Attendance Machine Sensor Repair & Battery',
                'category' => 'Electronics',
                'amount' => 6400,
                'description' => 'Optical scanner recalibration and motherboard power unit fix.',
                'payment_method' => 'Cash',
                'date' => '2026-08-26 10:30:00',
            ],

            // 🟠 TIER 3: Moderate (25,000 - 50,000 PKR)
            [
                'title' => 'Alpha Stationery Examination Answer Sheet Booklets (5,000 Copies)',
                'category' => 'Printing',
                'amount' => 45000,
                'description' => 'Custom 16-page answer booklets with official watermark for institutional examinations.',
                'payment_method' => 'Cheque',
                'cheque_id' => $chq2->id,
                'payment_reference' => 'Cheque #CHQ-890122',
                'date' => '2026-08-05 14:00:00',
            ],
            [
                'title' => 'PTCL Dedicated High-Speed Optical Fiber Internet Lease',
                'category' => 'Utilities',
                'amount' => 36000,
                'description' => 'Annual institutional connectivity for Computer Lab, Digital Library and Admin Office.',
                'payment_method' => 'Cheque',
                'cheque_id' => $chq5->id,
                'payment_reference' => 'Cheque #CHQ-890125',
                'date' => '2026-08-18 11:30:00',
            ],
            [
                'title' => 'CCTV Security Cameras DVR 4TB Surveillance Hard Drive & Wiring',
                'category' => 'Security',
                'amount' => 38500,
                'description' => 'Upgraded main campus gate and corridor 32-channel DVR recording system with WD Purple drive.',
                'payment_method' => 'Cash',
                'date' => '2026-08-20 15:00:00',
            ],
            [
                'title' => 'Solar Hybrid Inverter 200Ah Tubular Battery Maintenance Service',
                'category' => 'Maintenance',
                'amount' => 28000,
                'description' => 'Distilled water top-up, acid gravity balancing, and terminal cleaning for 8 solar batteries.',
                'payment_method' => 'Cash',
                'date' => '2026-08-23 12:00:00',
            ],
            [
                'title' => 'Auditorium Public Address (PA) Sound System & Wireless Mics',
                'category' => 'Events',
                'amount' => 31500,
                'description' => 'Replaced blown horn speakers and serviced Yamaha mixer amplifier for main hall.',
                'payment_method' => 'Cash',
                'date' => '2026-08-25 14:45:00',
            ],
            [
                'title' => 'Grade 8 & 9 Robotics & Arduino STEM Learning Starter Kits',
                'category' => 'Lab Supplies',
                'amount' => 48000,
                'description' => '10 sets of sensor kits, servo motors, breadboards, and microcontroller modules for STEM club.',
                'payment_method' => 'Cash',
                'date' => '2026-08-27 10:15:00',
            ],

            // 🔴 TIER 4: High Value (> 50,000 PKR)
            [
                'title' => 'LESCO High Tension Campus Electricity Bill (July/August)',
                'category' => 'Utilities',
                'amount' => 185000,
                'description' => 'Commercial 3-phase grid power supply invoice for classrooms, labs, and admin complex.',
                'payment_method' => 'Cheque',
                'cheque_id' => $chq3->id,
                'payment_reference' => 'Cheque #CHQ-890123',
                'date' => '2026-08-10 11:00:00',
            ],
            [
                'title' => 'Falcon Elite Outsourced Campus Security Guards Contract Invoice',
                'category' => 'Security',
                'amount' => 75000,
                'description' => 'Monthly armed security personnel deployment for main gate and perimeter posts.',
                'payment_method' => 'Cheque',
                'cheque_id' => $chq6->id,
                'payment_reference' => 'Cheque #CHQ-890126',
                'date' => '2026-08-20 10:30:00',
            ],
            [
                'title' => 'Auditorium Acoustic Wooden Wall Panels & Stage Polishing',
                'category' => 'Infrastructure',
                'amount' => 115000,
                'description' => 'Termite damage restoration and acoustic panel varnishing across 1,500 sq ft hall.',
                'payment_method' => 'Cash',
                'date' => '2026-08-24 16:00:00',
            ],
            [
                'title' => 'Physics Laboratory Digital Storage Oscilloscopes & Power Supplies',
                'category' => 'Lab Supplies',
                'amount' => 95000,
                'description' => '2 Uni-T 100MHz digital oscilloscopes and 4 variable DC regulated power supplies for F.Sc labs.',
                'payment_method' => 'Cash',
                'date' => '2026-08-28 11:30:00',
            ],
        ];

        foreach ($mockExpenses as $exp) {
            $tier = Expense::resolveTier((float) $exp['amount']);

            Expense::create([
                'title' => $exp['title'],
                'category' => $exp['category'],
                'amount' => $exp['amount'],
                'amount_tier' => $tier['tier'],
                'tier_color' => $tier['color'],
                'description' => $exp['description'],
                'created_by' => $userId,
                'status' => 'disbursed',
                'disbursed_at' => $exp['date'],
                'disbursed_by' => $userId,
                'payment_method' => $exp['payment_method'],
                'payment_reference' => $exp['payment_reference'] ?? null,
                'cheque_id' => $exp['cheque_id'] ?? null,
                'created_at' => $exp['date'],
                'updated_at' => $exp['date'],
            ]);
        }
    }
}
