<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maps raw ZKTeco punches (bio_punches) into real student attendance rows.
 *
 * Rules:
 *  - A punch maps to a student via students.bio_user_id, falling back to
 *    students.registration_no (device enrolled with the registration number).
 *  - First mapped punch of a day wins; unique (student_id, date) key on
 *    student_attendances guarantees single daily entry per student.
 *  - If a teacher already marked the student himself, biometric data never
 *    downgrades or overrides that decision (present/late only fill the gaps).
 */
class SyncBioAttendance extends Command
{
    protected $signature = 'attendance:sync-bio {--limit= : Max punches to map this run}';

    protected $description = 'Map raw biometric punches into student attendance rosters';

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?: config('attendance.batch_size', 2000));

        $punches = DB::table('bio_punches')
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($punches->isEmpty()) {
            return 0;
        }

        $this->info('Mapping ' . $punches->count() . ' unprocessed punches...');

        // ---- Build the ID -> student lookup table for this batch ----
        $bioIds = $punches->pluck('bio_user_id')->unique()->values();
        $students = Student::where('status', 'active')
            ->where(function ($query) use ($bioIds) {
                $query->whereIn('bio_user_id', $bioIds)->orWhereIn('registration_no', $bioIds);
            })
            ->get()
            ->keyBy(function (Student $student) {
                // Both identities can point at the same student row.
                return $student->bio_user_id ?: $student->registration_no;
            });

        $lateCutoff = Carbon::parse(config('attendance.late_cutoff', '08:00'));
        $session = config('attendance.session', '2026-2027');
        $marker = config('attendance.marker_label', 'ZKTeco biometric device');

        $mapped = 0;
        $skipped = 0;

        DB::transaction(function () use ($punches, $students, $lateCutoff, $session, $marker, &$mapped, &$skipped) {
            $attendanceInserts = [];
            $processedIds = [];

            foreach ($punches as $punch) {
                $punchDatetime = Carbon::parse($punch->punch_time);
                $punchDate = $punchDatetime->toDateString();
                $student = $students->get($punch->bio_user_id);

                if (!$student) {
                    $skipped++;
                    $processedIds[$punch->id] = [
                        'processed_at' => now(),
                        'process_note' => 'Unmapped device ID "' . $punch->bio_user_id . '" - no student with this bio_user_id/registration_no',
                    ];
                    continue;
                }

                // Daily unique (student_id, date) — set the row once.
                $alreadyMarked = DB::table('student_attendances')
                    ->where('student_id', $student->id)
                    ->where('date', $punchDate)
                    ->exists();

                if (!$alreadyMarked) {
                    $status = $punchDatetime->format('H:i:s') > $lateCutoff->format('H:i:s') ? 'late' : 'present';

                    $attendanceInserts[] = [
                        'student_id' => $student->id,
                        'date' => $punchDate,
                        'session' => $student->session ?: $session,
                        'class' => $student->class,
                        'section' => $student->section,
                        'status' => $status,
                        'teacher_name' => $marker,
                        'teacher_id' => null,
                        'remarks' => 'Biometric first punch ' . $punchDatetime->format('H:i'),
                        'marked_at' => $punchDatetime,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $mapped++;

                    $processedIds[$punch->id] = [
                        'processed_at' => now(),
                        'process_note' => 'Mapped to student ' . $student->id . ' as ' . $status,
                    ];
                } else {
                    // Row exists (teacher- or bio-marked earlier in this run);
                    // never override a human's status with the device data.
                    $processedIds[$punch->id] = [
                        'processed_at' => now(),
                        'process_note' => 'Daily roster already exists for ' . $punchDate,
                    ];
                    $skipped++;
                }
            }

            if ($attendanceInserts !== []) {
                // Chunked inserts with the unique key in play — nothing here can
                // blow up on duplication; ignore-styling keeps re-runs safe.
                foreach (array_chunk($attendanceInserts, 250) as $chunk) {
                    DB::table('student_attendances')->insertOrIgnore($chunk);
                }
            }

            foreach ($processedIds as $id => $update) {
                DB::table('bio_punches')->where('id', $id)->update($update);
            }
        });

        $this->info("Done. Mapped: {$mapped}, skipped/already-marked: {$skipped}");

        return 0;
    }
}
