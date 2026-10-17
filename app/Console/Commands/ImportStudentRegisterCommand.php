<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StudentRegisterImportService;
use Exception;

class ImportStudentRegisterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'students:import-register {path? : Path to the student register .xlsx file} {--session=2026-2027 : Academic session e.g. 2026-2027}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import students and extract profile photos from a government register Excel file';

    /**
     * Execute the console command.
     */
    public function handle(StudentRegisterImportService $importer)
    {
        $defaultPath = '/Users/shahbazali/Downloads/student_register-2026-09-12-133247.xlsx';
        $path = $this->argument('path') ?: $defaultPath;
        $session = $this->option('session') ?: '2026-2027';

        if (!file_exists($path)) {
            $this->error("File not found at: {$path}");
            return Command::FAILURE;
        }

        $this->info("Starting import from: {$path}");
        $this->info("Academic Session: {$session}");
        $this->info("File size: " . round(filesize($path) / (1024 * 1024), 2) . " MB");

        try {
            $startTime = microtime(true);
            $results = $importer->import($path, null, $session);
            $duration = round(microtime(true) - $startTime, 2);

            $this->info("Import completed successfully in {$duration} seconds!");
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Academic Session', $results['session']],
                    ['Total Rows Processed', $results['total_processed']],
                    ['New Students Created', $results['created_count']],
                    ['Existing Students Updated', $results['updated_count']],
                    ['Student Photos Extracted', $results['photos_attached']],
                ]
            );

            if (!empty($results['classes_summary'])) {
                $this->info("\nClass-wise breakdown:");
                $classRows = [];
                foreach ($results['classes_summary'] as $class => $count) {
                    $classRows[] = [$class, $count];
                }
                $this->table(['Class', 'Students'], $classRows);
            }

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Import failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
