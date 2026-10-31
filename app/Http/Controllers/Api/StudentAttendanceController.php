<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SchoolSection;
use App\Models\Teacher;
use App\Services\AbsenteeNotificationService;
use App\Jobs\SendAbsenteeWhatsAppBatchJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Mpdf\Mpdf;
use Exception;

class StudentAttendanceController extends Controller
{
    protected $authorizedRoles = ['hr', 'principal', 'office_admin', 'super_admin', 'admin'];

    /**
     * Authorize access.
     */
    protected function authorizeAccess(Request $request)
    {
        $role = $request->user()?->role;
        if (!in_array($role, $this->authorizedRoles)) {
            abort(403, 'Unauthorized access to Student Attendance.');
        }
    }

    /**
     * Get class roster with attendance status for a specific date.
     */
    public function classSheet(Request $request)
    {
        $this->authorizeAccess($request);

        $date = $request->input('date', Carbon::today('Asia/Karachi')->format('Y-m-d'));
        $session = $request->input('session', '2026-2027') ?: '2026-2027';
        $class = $request->input('class', 'ECE/Nur');
        $section = $request->input('section', 'A') ?: 'A';

        // Fetch students in this class and section with only required columns
        $students = Student::where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->select([
                'id',
                'registration_no',
                'student_name',
                'father_name',
                'b_form_no',
                'class',
                'section',
                'gender',
                'mobile_no',
                'photo_path',
            ])
            ->orderBy('student_name', 'asc')
            ->get();

        // Fetch attendance records for this date
        $attendances = StudentAttendance::where('date', $date)
            ->where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->select(['id', 'student_id', 'status', 'teacher_name', 'remarks', 'marked_at'])
            ->get()
            ->keyBy('student_id');

        $isMarked = $attendances->isNotEmpty();
        $firstMarked = $attendances->first();
        $teacherName = $firstMarked?->teacher_name;
        $markedAt = $firstMarked?->marked_at;

        $presentCount = 0;
        $absentCount = 0;
        $lateCount = 0;
        $leaveCount = 0;

        $roster = $students->map(function ($student) use ($attendances, &$presentCount, &$absentCount, &$lateCount, &$leaveCount) {
            $att = $attendances->get($student->id);
            // Students remain absent until a machine punch maps them.
            $status = $att ? $att->status : 'absent';
            $remarks = $att ? $att->remarks : '';

            if ($status === 'present') $presentCount++;
            elseif ($status === 'absent') $absentCount++;
            elseif ($status === 'late') $lateCount++;
            elseif ($status === 'leave') $leaveCount++;

            return [
                'id'              => $student->id,
                'registration_no' => $student->registration_no,
                'student_name'    => $student->student_name,
                'father_name'     => $student->father_name,
                'b_form_no'       => $student->b_form_no,
                'class'           => $student->class,
                'section'         => $student->section,
                'gender'          => $student->gender,
                'mobile_no'       => $student->mobile_no,
                'photo_url'       => $student->photo_url,
                'attendance_status' => $status,
                'remarks'         => $remarks,
            ];
        });

        // Overview of all sections for this class on this date
        $allSections = SchoolSection::where('session', $session)
            ->where('class', $class)
            ->pluck('name')
            ->toArray();

        if (empty($allSections)) {
            $allSections = ['A'];
        }

        // Bulk aggregate student counts per section (1 query)
        $sectionStudentCounts = Student::where('session', $session)
            ->where('class', $class)
            ->selectRaw('section, count(*) as total')
            ->groupBy('section')
            ->pluck('total', 'section');

        // Count explicit statuses for current roster members, including unsaved absences.
        $sectionAttStats = Student::leftJoin('student_attendances as attendance', function ($join) use ($date, $session, $class) {
                $join->on('attendance.student_id', '=', 'students.id')
                    ->on('attendance.section', '=', 'students.section')
                    ->where('attendance.date', '=', $date)
                    ->where('attendance.session', '=', $session)
                    ->where('attendance.class', '=', $class);
            })
            ->where('students.session', $session)
            ->where('students.class', $class)
            ->selectRaw('students.section, count(attendance.id) as total_att, sum(case when attendance.status = "present" then 1 else 0 end) as present_count, sum(case when coalesce(attendance.status, "absent") = "absent" then 1 else 0 end) as absent_count, sum(case when attendance.status = "late" then 1 else 0 end) as late_count, sum(case when attendance.status = "leave" then 1 else 0 end) as leave_count, min(attendance.teacher_name) as teacher_name, max(attendance.marked_at) as marked_at')
            ->groupBy('students.section')
            ->get()
            ->keyBy('section');

        $classProgress = [];
        foreach ($allSections as $secName) {
            $total = (int) ($sectionStudentCounts[$secName] ?? 0);
            $att = $sectionAttStats->get($secName);
            $isSecMarked = $att && $att->total_att > 0;
            $secAbsent = $att ? (int) $att->absent_count : $total;
            $secPresent = $att ? (int) $att->present_count : 0;
            $markedAtTime = $att && $att->marked_at ? Carbon::parse($att->marked_at)->format('h:i A') : null;

            $classProgress[] = [
                'section'      => $secName,
                'total'        => $total,
                'is_marked'    => $isSecMarked,
                'present'      => $secPresent,
                'absent'       => $secAbsent,
                'late'         => $att ? (int) $att->late_count : 0,
                'leave'        => $att ? (int) $att->leave_count : 0,
                'teacher_name' => $att?->teacher_name,
                'marked_at'    => $markedAtTime,
            ];
        }

        return response()->json([
            'date'           => $date,
            'session'        => $session,
            'class'          => $class,
            'section'        => $section,
            'is_marked'      => $isMarked,
            'teacher_name'   => $teacherName,
            'marked_at'      => $markedAt ? $markedAt->format('h:i A') : null,
            'total_students' => $students->count(),
            'present_count'  => $presentCount,
            'absent_count'   => $absentCount,
            'late_count'     => $lateCount,
            'leave_count'    => $leaveCount,
            'roster'         => $roster,
            'class_progress' => $classProgress,
        ]);
    }

    /**
     * Submit attendance for a single class & section.
     */
    public function submitClass(Request $request)
    {
        $this->authorizeAccess($request);

        $request->validate([
            'date'           => 'required|date_format:Y-m-d',
            'session'        => 'required|string|max:50',
            'class'          => 'required|string|max:100',
            'section'        => 'required|string|max:50',
            'teacher_name'   => 'nullable|string|max:150',
            'teacher_id'     => 'nullable|integer',
            'attendances'    => 'required|array|min:1',
            'attendances.*.student_id' => 'required|integer|exists:students,id',
            'attendances.*.status'     => 'required|in:present,absent,late,leave',
            'attendances.*.remarks'    => 'nullable|string|max:255',
        ]);

        $date = $request->date;
        $session = $request->session;
        $class = $request->class;
        $section = $request->section;
        $teacherName = trim($request->teacher_name ?? '');
        $teacherId = $request->teacher_id;
        $userId = $request->user()?->id;
        $now = Carbon::now();

        $studentIds = collect($request->attendances)->pluck('student_id')->unique()->values();
        $rosterIds = Student::where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->whereIn('id', $studentIds)
            ->pluck('id');

        if ($rosterIds->count() !== $studentIds->count()) {
            return response()->json(['message' => 'Students must belong to the selected class, section and session.'], 422);
        }

        $firstPunches = DB::table('bio_device_users as enrollment')
            ->join('bio_punches as punch', function ($join) {
                $join->on('punch.machine_sn', '=', 'enrollment.machine_sn')
                    ->on('punch.bio_user_id', '=', 'enrollment.device_user_id');
            })
            ->whereIn('enrollment.student_id', $rosterIds)
            ->whereDate('punch.punch_time', $date)
            ->selectRaw('enrollment.student_id, min(punch.punch_time) as first_punch')
            ->groupBy('enrollment.student_id')
            ->pluck('first_punch', 'student_id');
        $lateCutoff = Carbon::parse(config('attendance.late_cutoff', '08:00'))->format('H:i:s');
        $existingAttendances = StudentAttendance::where('date', $date)
            ->whereIn('student_id', $rosterIds)
            ->get(['student_id', 'marked_by', 'marked_at'])
            ->keyBy('student_id');

        $rows = [];
        $present = 0;
        $absent = 0;
        $late = 0;
        $leave = 0;

        foreach ($request->attendances as $item) {
            $status = $item['status'];
            $existingAttendance = $existingAttendances->get($item['student_id']);
            $firstPunch = $firstPunches->get($item['student_id']);
            if ($firstPunch) {
                $status = Carbon::parse($firstPunch)->format('H:i:s') > $lateCutoff ? 'late' : 'present';
            } elseif (in_array($status, ['present', 'late'], true)) {
                return response()->json(['message' => 'Present or late attendance requires a linked machine punch for the selected date.'], 422);
            }

            if ($status === 'present') $present++;
            elseif ($status === 'absent') $absent++;
            elseif ($status === 'late') $late++;
            elseif ($status === 'leave') $leave++;

            $rows[] = [
                'student_id'   => $item['student_id'],
                'date'         => $date,
                'session'      => $session,
                'class'        => $class,
                'section'      => $section,
                'status'       => $status,
                'teacher_name' => $teacherName ?: null,
                'teacher_id'   => $teacherId ?: null,
                'remarks'      => $item['remarks'] ?? null,
                'marked_by'    => $existingAttendance ? $existingAttendance->marked_by : $userId,
                'marked_at'    => $existingAttendance ? $existingAttendance->marked_at : $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            StudentAttendance::upsert(
                $rows,
                ['student_id', 'date'],
                ['status', 'session', 'class', 'section', 'teacher_name', 'teacher_id', 'remarks', 'marked_by', 'marked_at', 'updated_at']
            );
        });

        return response()->json([
            'status'  => 'success',
            'message' => "Attendance for {$class} (Sec {$section}) saved successfully!",
            'summary' => [
                'total'        => count($rows),
                'present'      => $present,
                'absent'       => $absent,
                'late'         => $late,
                'leave'        => $leave,
                'teacher_name' => $teacherName,
                'marked_at'    => $now->format('h:i A'),
            ],
        ]);
    }

    /**
     * Daily School-wide Attendance Monitor & Absentee List.
     */
    /**
     * Branded daily attendance report (PDF) for one class sheet.
     */
    public function dailyReportPdf(Request $request)
    {
        $this->authorizeAccess($request);

        $date = $request->input('date', Carbon::today('Asia/Karachi')->format('Y-m-d'));
        $session = $request->input('session', '2026-2027') ?: '2026-2027';
        $class = $request->input('class', 'ECE/Nur');
        $section = $request->input('section', 'A') ?: 'A';

        $students = Student::where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->orderBy('student_name')
            ->get(['id', 'registration_no', 'student_name', 'father_name', 'photo_path', 'class', 'section']);

        $attendances = StudentAttendance::where('date', $date)
            ->where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->get(['student_id', 'status', 'teacher_name', 'remarks', 'marked_at'])
            ->keyBy('student_id');

        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
        $unmarked = 0;
        foreach ($students as $student) {
            $att = $attendances->get($student->id);
            $status = $att?->status ?? 'absent';
            if (isset($counts[$status])) $counts[$status]++;
            if (!$att) $unmarked++;
        }

        $teacherNote = $attendances->first()?->teacher_name;
        $logoB64 = $this->fileDataUri(resource_path('assets/nsse_logo.png'));

        $statusChip = function ($status) {
            $map = [
                'present' => ['E8F5EC', '14532D', 'Present'],
                'late'    => ['FFF7E0', '8A6D1A', 'Late'],
                'absent'  => ['FDE8E8', 'B91C1C', 'Absent'],
                'leave'   => ['E8F0FE', '1D4ED8', 'Leave'],
            ];
            [$bg, $fg, $label] = $map[$status] ?? ['F3F4F6', '6B7280', '—'];
            return '<span style="display:inline-block;padding:1.5mm 3.5mm;border-radius:3mm;background-color:#' . $bg . ';color:#' . $fg . ';font-weight:bold;font-size:8.5pt;">' . $label . '</span>';
        };

        $photoCell = function ($student) {
            if ($student->photo_path) {
                $uri = $this->fileDataUri(storage_path('app/public/' . $student->photo_path), 80, 96, true);
                if ($uri) {
                    return '<img src="' . $uri . '" width="34" height="40" style="object-fit:cover;border:1px solid #CFE3D6;" />';
                }
            }
            return '<div style="width:34px;height:40px;background-color:#EDF5F0;border:1px solid #CFE3D6;border-radius:6px;color:#9AB5A5;text-align:center;line-height:40px;font-size:6pt;">NO PHOTO</div>';
        };

        $rows = '';
        $n = 0;
        foreach ($students as $student) {
            $n++;
            $att = $attendances->get($student->id);
            $status = $att?->status ?? 'absent';
            $remarks = $att?->remarks ?: '';
            $markedAt = $att?->marked_at ? Carbon::parse($att->marked_at)->format('H:i') : '';
            $rows .= '<tr>
                <td style="text-align:center;color:#6B7280;font-size:9pt;">' . $n . '</td>
                <td style="text-align:center;">' . $photoCell($student) . '</td>
                <td style="font-size:8.5pt;color:#374151;font-family:monospace;">' . e($student->registration_no) . '</td>
                <td style="font-weight:bold;color:#1A2520;font-size:9.5pt;">' . e($student->student_name) . '</td>
                <td style="font-size:8.5pt;color:#6B7280;">' . e($student->father_name ?: '—') . '</td>
                <td style="font-size:8pt;color:#374151;">' . e($remarks ?: '—') . ($markedAt ? ' <span style="color:#9CA3AF;">' . $markedAt . '</span>' : '') . '</td>
                <td style="text-align:center;">' . $statusChip($status) . '</td>
            </tr>';
        }

        $dateHuman = Carbon::parse($date)->format('l, d F Y');
        $generatedAt = Carbon::now()->format('d M Y, g:ia');
        $teacherNoteDisplay = $teacherNote ?: '—';
        $studentCount = $students->count();

        $html = <<<HTML
<style>
    body { font-family: helvetica; color:#24332A; }
    table.roster { width:100%; border-collapse:collapse; }
    table.roster thead { display:table-header-group; }
    table.roster th { background-color:#164E35; color:#FFFFFF; font-size:7.5pt; text-transform:uppercase;
        padding:3mm 2mm; letter-spacing:0.55pt; border-bottom:2px solid #A8D5B5; }
    table.roster td { border-bottom:0.7px solid #DDEBE1; padding:2.2mm 2.5mm; vertical-align:middle; }
    table.roster tr:nth-child(even) td { background-color:#F7FBF8; }
    table.roster tr { page-break-inside:avoid; }
    .metric { border:0.7px solid #D8E9DD; background-color:#FFFFFF; padding:2.5mm 3mm; }
    .metric-label { color:#718078; font-size:7pt; text-transform:uppercase; letter-spacing:0.45pt; }
    .metric-value { font-size:16pt; font-weight:bold; line-height:1.1; }
</style>

<table style="width:100%; background-color:#164E35; color:#FFFFFF; border-radius:4mm 4mm 0 0; margin-bottom:0;">
<tr>
    <td style="width:22mm; padding:4.5mm 3mm 4.5mm 4mm;">
        <img src="{$logoB64}" width="54" height="54" />
    </td>
    <td style="padding:4.5mm 3mm;">
        <div style="font-size:7pt; letter-spacing:1.55pt; color:#BFE3CB; font-weight:bold;">NAWAZ SHARIF SCHOOL OF EMINENCE</div>
        <div style="font-size:17pt; font-weight:bold; margin-top:1.2mm;">Daily Attendance</div>
        <div style="font-size:9pt; color:#D9EFDF; margin-top:1mm;">Class register · {$dateHuman}</div>
    </td>
    <td style="padding:4.5mm; text-align:right;">
        <div style="font-size:8pt; color:#FFFFFF; letter-spacing:0.7pt; font-weight:bold;">{$class} · SECTION {$section}</div>
        <div style="font-size:8pt; color:#BFE3CB; margin-top:1mm;">SESSION {$session}</div>
    </td>
</tr>
</table>

<table style="width:100%; border:0.7px solid #D8E9DD; border-collapse:separate; border-spacing:2mm; background-color:#F1F8F3; margin-bottom:3mm;">
<tr>
    <td class="metric" style="width:25%;">
        <div class="metric-label">Present</div>
        <div class="metric-value" style="color:#167044;">{$counts['present']}</div>
    </td>
    <td class="metric" style="width:25%;">
        <div class="metric-label">Late</div>
        <div class="metric-value" style="color:#A06A09;">{$counts['late']}</div>
    </td>
    <td class="metric" style="width:25%;">
        <div class="metric-label">Absent</div>
        <div class="metric-value" style="color:#B42318;">{$counts['absent']}</div>
    </td>
    <td class="metric" style="width:25%;">
        <div class="metric-label">Leave</div>
        <div class="metric-value" style="color:#2459A6;">{$counts['leave']}</div>
    </td>
</tr>
</table>

<table style="width:100%; border-collapse:collapse; margin-bottom:4mm;">
<tr>
    <td style="padding:2.5mm 3mm; background-color:#F7FBF8; border-left:2px solid #5CA875; font-size:8.5pt; color:#66756C;">
        <span style="color:#164E35; font-weight:bold;">{$studentCount} students</span>
        &nbsp;·&nbsp; {$unmarked} without saved attendance
    </td>
    <td style="padding:2.5mm 3mm; background-color:#F7FBF8; text-align:right; font-size:8.5pt; color:#66756C;">
        Marked by: <b style="color:#24332A;">{$teacherNoteDisplay}</b>
    </td>
</tr>
</table>

<table class="roster">
<tr>
    <th style="width:8mm;">#</th>
    <th style="width:15mm;">Photo</th>
    <th style="width:26mm;">Reg. No</th>
    <th style="text-align:left;">Student Name</th>
    <th style="text-align:left;">Father</th>
    <th style="text-align:left;">Remarks</th>
    <th style="width:24mm;">Status</th>
</tr>
{$rows}
</table>

<div style="margin-top:4mm; text-align:center; color:#9CA3AF; font-size:8pt;">
    Generated by NSSE Portal · Bio attendance system · {$generatedAt}
</div>
HTML;

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 12,
            'margin_bottom' => 14,
            'margin_header' => 0,
            'margin_footer' => 8,
            'tempDir' => sys_get_temp_dir(),
        ]);

        $mpdf->SetFooter('NSSE Portal — Daily Attendance Report||{PAGENO} of {nbpg}');
        $mpdf->WriteHTML($html);

        $fileName = 'attendance_' . str_replace(['(', ')', ' '], '_', $class) . '_' . $section . '_' . $date . '.pdf';
        return response($mpdf->Output($fileName, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function fileDataUri(string $path, int $maxWidth = 144, int $maxHeight = 144, bool $jpeg = false): ?string
    {
        if (!is_file($path)) return null;
        $content = @file_get_contents($path);
        if ($content === false) return null;
        $mime = @mime_content_type($path) ?: 'image/png';

        // Embed report-sized images instead of full-resolution originals.
        // Keep the original file untouched and preserve PNG logo transparency.
        if (function_exists('imagecreatefromstring')) {
            $source = @imagecreatefromstring($content);
            if ($source !== false) {
                $width = imagesx($source);
                $height = imagesy($source);
                $scale = min(1, $maxWidth / $width, $maxHeight / $height);
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                if ($jpeg) {
                    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
                } else {
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
                }
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                ob_start();
                $encoded = $jpeg ? imagejpeg($target, null, 80) : imagepng($target, null, 9);
                $optimized = ob_get_clean();
                imagedestroy($target);
                imagedestroy($source);
                if ($encoded && $optimized !== false) {
                    $content = $optimized;
                    $mime = $jpeg ? 'image/jpeg' : 'image/png';
                }
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    public function dailySummary(Request $request)
    {        $this->authorizeAccess($request);

        $date = $request->input('date', Carbon::today('Asia/Karachi')->format('Y-m-d'));
        $session = $request->input('session', '2026-2027') ?: '2026-2027';

        $totalEnrolled = Student::where('session', $session)->count();

        $stats = StudentAttendance::where('date', $date)
            ->where('session', $session)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalMarked = $stats->sum();
        $present = $stats['present'] ?? 0;
        $absent = $stats['absent'] ?? 0;
        $late = $stats['late'] ?? 0;
        $leave = $stats['leave'] ?? 0;
        $attendanceRate = $totalMarked > 0 ? round(($present / $totalMarked) * 100, 1) : 0;

        // Class-by-class submission progress
        $classBreakdown = StudentAttendance::where('date', $date)
            ->where('session', $session)
            ->selectRaw('class, section, teacher_name, max(marked_at) as last_marked_at, sum(case when status="present" then 1 else 0 end) as present, sum(case when status="absent" then 1 else 0 end) as absent, count(*) as total')
            ->groupBy('class', 'section', 'teacher_name')
            ->get();

        // Today's absentees with parent contact numbers and follow-up tracking
        $absentees = StudentAttendance::where('date', $date)
            ->where('session', $session)
            ->where('status', 'absent')
            ->with([
                'student:id,student_name,father_name,mobile_no,b_form_no,photo_path,class,section,registration_no',
                'followedUpByUser:id,name'
            ])
            ->get()
            ->map(function ($att) {
                return [
                    'id'                  => $att->id,
                    'student_id'          => $att->student_id,
                    'student_name'        => $att->student?->student_name,
                    'father_name'         => $att->student?->father_name,
                    'registration_no'     => $att->student?->registration_no,
                    'mobile_no'           => $att->student?->mobile_no,
                    'photo_url'           => $att->student?->photo_url,
                    'class'               => $att->class,
                    'section'             => $att->section,
                    'teacher_name'        => $att->teacher_name,
                    'remarks'             => $att->remarks,
                    'follow_up_status'    => $att->follow_up_status ?: 'pending',
                    'follow_up_reason'    => $att->follow_up_reason,
                    'follow_up_notes'     => $att->follow_up_notes,
                    'followed_up_by'      => $att->followed_up_by,
                    'followed_up_by_name' => $att->followedUpByUser?->name,
                    'followed_up_at'      => $att->followed_up_at ? $att->followed_up_at->format('h:i A') : null,
                    'whatsapp_status'     => $att->whatsapp_status ?: 'pending',
                    'whatsapp_msg_id'     => $att->whatsapp_msg_id,
                    'whatsapp_sent_at'    => $att->whatsapp_sent_at ? $att->whatsapp_sent_at->format('h:i A') : null,
                    'whatsapp_error'      => $att->whatsapp_error,
                ];
            });

        $followUpSummary = [
            'total_absent' => $absent,
            'contacted'    => $absentees->where('follow_up_status', 'contacted')->count(),
            'pending'      => $absentees->where('follow_up_status', 'pending')->count(),
            'unreachable'  => $absentees->where('follow_up_status', 'unreachable')->count(),
        ];

        $whatsappSummary = [
            'total_absent' => $absent,
            'sent'         => $absentees->where('whatsapp_status', 'sent')->count(),
            'queued'       => $absentees->where('whatsapp_status', 'queued')->count(),
            'failed'       => $absentees->where('whatsapp_status', 'failed')->count(),
            'skipped'      => $absentees->where('whatsapp_status', 'skipped')->count(),
            'pending'      => $absentees->filter(fn($a) => in_array($a['whatsapp_status'], ['pending', null]))->count(),
        ];

        return response()->json([
            'date'              => $date,
            'session'           => $session,
            'total_enrolled'    => $totalEnrolled,
            'total_marked'      => $totalMarked,
            'present'           => $present,
            'absent'            => $absent,
            'late'              => $late,
            'leave'             => $leave,
            'attendance_rate'   => $attendanceRate,
            'classes_summary'   => $classBreakdown,
            'follow_up_summary' => $followUpSummary,
            'whatsapp_summary'  => $whatsappSummary,
            'absentees'         => $absentees,
        ]);
    }

    /**
     * Save parent follow-up discussion & outcome for an absent student.
     */
    public function saveFollowUp(Request $request, $id)
    {
        $this->authorizeAccess($request);

        $request->validate([
            'follow_up_status' => 'required|string|in:pending,contacted,unreachable,not_required',
            'follow_up_reason' => 'nullable|string|max:100',
            'follow_up_notes'  => 'nullable|string|max:1000',
        ]);

        $attendance = StudentAttendance::findOrFail($id);
        $user = $request->user();

        $attendance->update([
            'follow_up_status' => $request->follow_up_status,
            'follow_up_reason' => $request->follow_up_reason,
            'follow_up_notes'  => $request->follow_up_notes,
            'followed_up_by'   => $user?->id,
            'followed_up_at'   => Carbon::now(),
        ]);

        $attendance->load('followedUpByUser:id,name', 'student');

        return response()->json([
            'status'  => 'success',
            'message' => 'Parent follow-up discussion saved successfully!',
            'data'    => [
                'id'                  => $attendance->id,
                'student_id'          => $attendance->student_id,
                'student_name'        => $attendance->student?->student_name,
                'follow_up_status'    => $attendance->follow_up_status,
                'follow_up_reason'    => $attendance->follow_up_reason,
                'follow_up_notes'     => $attendance->follow_up_notes,
                'followed_up_by'      => $attendance->followed_up_by,
                'followed_up_by_name' => $attendance->followedUpByUser?->name,
                'followed_up_at'      => $attendance->followed_up_at ? $attendance->followed_up_at->format('h:i A') : null,
            ]
        ]);
    }

    /**
     * Get list of teacher names for dropdown.
     */
    public function teachersList(Request $request)
    {
        $this->authorizeAccess($request);

        // Fetch from Teacher model if any, and also recent attendances
        $teachers = Teacher::orderBy('name', 'asc')->pluck('name')->toArray();

        $recentAttendanceTeachers = StudentAttendance::distinct()
            ->whereNotNull('teacher_name')
            ->pluck('teacher_name')
            ->toArray();

        $all = array_values(array_unique(array_filter(array_merge($teachers, $recentAttendanceTeachers))));

        if (empty($all)) {
            $all = [
                'Sir Tariq',
                'Madam Ayesha',
                'Sir Usman',
                'Madam Fatima',
                'Sir Muhammad Ali',
                'Madam Sana',
                'Sir Hamza',
                'Madam Zainab',
            ];
        }

        return response()->json($all);
    }

    /**
     * Get default WhatsApp message template and sample preview.
     */
    public function getWhatsAppTemplate(Request $request, AbsenteeNotificationService $notificationService, \App\Services\WhatsAppService $whatsappService)
    {
        $this->authorizeAccess($request);

        $defaultTemplate = $notificationService->getDefaultTemplate();
        
        // Render sample preview using first absentee of today if available, or dummy sample
        $date = $request->input('date', Carbon::today()->format('Y-m-d'));
        $session = $request->input('session', '2026-2027') ?: '2026-2027';

        $sampleAtt = StudentAttendance::where('date', $date)
            ->where('session', $session)
            ->where('status', 'absent')
            ->with('student')
            ->first();

        $preview = $sampleAtt 
            ? $notificationService->renderMessage($sampleAtt, $defaultTemplate)
            : str_replace(
                ['{student_name}', '{father_name}', '{class}', '{section}', '{date}', '{school_name}'],
                ['Muhammad Ali', 'Tariq Mehmood', 'Prep', 'A', Carbon::parse($date)->format('d-M-Y'), 'Nawaz Shareef School of Excellence'],
                $defaultTemplate
            );

        return response()->json([
            'default_template' => $defaultTemplate,
            'preview'          => $preview,
            'anti_ban_info'    => $whatsappService->getAntiBanConfig(),
        ]);
    }

    /**
     * Broadcast WhatsApp alerts to absent students with anti-ban background queuing.
     */
    public function broadcastAbsenteeWhatsApp(Request $request, AbsenteeNotificationService $notificationService)
    {
        $this->authorizeAccess($request);

        $date = $request->input('date', Carbon::today()->format('Y-m-d'));
        $session = $request->input('session', '2026-2027') ?: '2026-2027';
        $class = $request->input('class');
        $attendanceIds = $request->input('attendance_ids', []);
        $customTemplate = $request->input('custom_template');
        $user = $request->user();

        // If specific IDs not provided, fetch unnotified absentees for this date & session
        if (empty($attendanceIds)) {
            $query = StudentAttendance::where('date', $date)
                ->where('session', $session)
                ->where('status', 'absent');

            if ($class && $class !== 'all') {
                $query->where('class', $class);
            }

            // Target pending / unnotified absentees or all absentees
            $attendanceIds = $query->pluck('id')->toArray();
        }

        if (empty($attendanceIds)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No absent students found matching the selected criteria.',
            ], 422);
        }

        // Mark attendance records as 'queued'
        StudentAttendance::whereIn('id', $attendanceIds)->update([
            'whatsapp_status' => 'queued',
            'whatsapp_error'  => null,
        ]);

        // Dispatch background job with anti-ban safeguards (12-22s random delay + 5 min rest after 100)
        SendAbsenteeWhatsAppBatchJob::dispatch($attendanceIds, $customTemplate, $user?->id);

        $count = count($attendanceIds);
        $estMinutes = round(($count * 17) / 60, 1);

        return response()->json([
            'status'        => 'success',
            'message'       => "Successfully queued {$count} WhatsApp absentee alert" . ($count > 1 ? 's' : '') . " for dispatch in background.",
            'queued_count'  => $count,
            'estimated_min' => $estMinutes,
            'anti_ban_active' => true,
        ]);
    }

    /**
     * Send instant single WhatsApp alert for a specific absentee.
     */
    public function sendSingleAbsenteeWhatsApp(Request $request, $id, AbsenteeNotificationService $notificationService)
    {
        $this->authorizeAccess($request);

        $attendance = StudentAttendance::with('student')->findOrFail($id);
        $customTemplate = $request->input('custom_template');
        $user = $request->user();

        $result = $notificationService->sendSingle($attendance, $customTemplate, $user?->id);

        if ($result['success']) {
            return response()->json([
                'status'          => 'success',
                'message'         => 'WhatsApp message delivered to parent successfully!',
                'whatsapp_msg_id' => $result['whatsapp_msg_id'] ?? null,
                'data'            => [
                    'id'               => $attendance->id,
                    'whatsapp_status'  => 'sent',
                    'whatsapp_msg_id'  => $result['whatsapp_msg_id'] ?? null,
                    'whatsapp_sent_at' => Carbon::now()->format('h:i A'),
                ]
            ]);
        }

        return response()->json([
            'status'  => 'error',
            'message' => $result['error'] ?? 'Failed to send WhatsApp message.',
        ], 400);
    }

    /**
     * Get section-wise monthly attendance register for all students of that section.
     */
    public function monthlyRegister(Request $request)
    {
        $this->authorizeAccess($request);

        $monthStr = $request->input('month', Carbon::today()->format('Y-m')); // e.g. "2026-09"
        $session = $request->input('session', '2026-2027') ?: '2026-2027';
        $class = $request->input('class', 'ECE/Nur');
        $section = $request->input('section', 'A') ?: 'A';

        try {
            $parsedDate = Carbon::parse($monthStr . '-01');
        } catch (Exception $e) {
            $parsedDate = Carbon::today()->startOfMonth();
        }

        $startOfMonth = $parsedDate->copy()->startOfMonth();
        $endOfMonth = $parsedDate->copy()->endOfMonth();
        $daysInMonth = $startOfMonth->daysInMonth;
        $today = Carbon::today();

        // Build list of all calendar days in this month
        $days = [];
        $workingDaysCount = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $currentDay = Carbon::create($startOfMonth->year, $startOfMonth->month, $d);
            $isSunday = $currentDay->isSunday();
            $isFuture = $currentDay->gt($today);
            
            if (!$isSunday && !$isFuture) {
                $workingDaysCount++;
            }

            $days[] = [
                'day'        => $d,
                'date'       => $currentDay->format('Y-m-d'),
                'day_name'   => $currentDay->format('D'),
                'is_sunday'  => $isSunday,
                'is_weekend' => $isSunday,
                'is_future'  => $isFuture,
                'is_today'   => $currentDay->isToday(),
            ];
        }

        // Fetch students of this class and section
        $students = Student::where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->select([
                'id',
                'registration_no',
                'student_name',
                'father_name',
                'b_form_no',
                'class',
                'section',
                'gender',
                'mobile_no',
                'photo_path',
            ])
            ->orderBy('student_name', 'asc')
            ->get();

        // Fetch all attendance records for this month and section in ONE fast query
        $attendances = StudentAttendance::whereBetween('date', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')])
            ->where('session', $session)
            ->where('class', $class)
            ->where('section', $section)
            ->select(['id', 'student_id', 'date', 'status', 'teacher_name', 'remarks'])
            ->get();

        // Group attendances by student_id and date
        $attendanceMap = [];
        $dailyTotals = [];
        foreach ($days as $dayObj) {
            $dailyTotals[$dayObj['date']] = [
                'present'  => 0,
                'absent'   => 0,
                'late'     => 0,
                'leave'    => 0,
                'unmarked' => 0,
                'total'    => 0,
            ];
        }

        foreach ($attendances as $att) {
            $d = $att->date instanceof Carbon ? $att->date->format('Y-m-d') : substr((string) $att->date, 0, 10);
            $sId = $att->student_id;
            if (!isset($attendanceMap[$sId])) {
                $attendanceMap[$sId] = [];
            }
            $attendanceMap[$sId][$d] = [
                'id'       => $att->id,
                'status'   => $att->status,
                'remarks'  => $att->remarks,
            ];

        }

        $totalPresentOverall = 0;
        $totalAbsentOverall = 0;
        $totalLateOverall = 0;
        $totalLeaveOverall = 0;
        $totalStudents = $students->count();

        // Build student rows with day-by-day matrix
        $studentRows = $students->map(function ($student) use ($days, $attendanceMap, &$dailyTotals, &$totalPresentOverall, &$totalAbsentOverall, &$totalLateOverall, &$totalLeaveOverall) {
            $studentAttMap = $attendanceMap[$student->id] ?? [];
            $records = [];
            $pCount = 0;
            $aCount = 0;
            $lCount = 0;
            $lvCount = 0;
            $markedDays = 0;

            foreach ($days as $dayObj) {
                $dateKey = $dayObj['date'];
                $att = $studentAttMap[$dateKey] ?? null;

                if ($att) {
                    $status = $att['status'];
                    $remarks = $att['remarks'];
                    $markedDays++;

                    if ($status === 'present') { $pCount++; $totalPresentOverall++; }
                    elseif ($status === 'absent') { $aCount++; $totalAbsentOverall++; }
                    elseif ($status === 'late') { $lCount++; $totalLateOverall++; }
                    elseif ($status === 'leave') { $lvCount++; $totalLeaveOverall++; }

                    $records[$dateKey] = [
                        'status'  => $status,
                        'code'    => match($status) { 'present' => 'P', 'absent' => 'A', 'late' => 'L', 'leave' => 'Lv', default => '—' },
                        'remarks' => $remarks,
                    ];
                } else {
                    if ($dayObj['is_sunday']) {
                        $records[$dateKey] = [
                            'status'  => 'sunday',
                            'code'    => 'Sun',
                            'remarks' => null,
                        ];
                    } elseif ($dayObj['is_future']) {
                        $records[$dateKey] = [
                            'status'  => 'future',
                            'code'    => '—',
                            'remarks' => null,
                        ];
                    } else {
                        $aCount++;
                        $totalAbsentOverall++;
                        $markedDays++;
                        $records[$dateKey] = [
                            'status'  => 'absent',
                            'code'    => 'A',
                            'remarks' => null,
                        ];
                    }
                }

                $status = $records[$dateKey]['status'];
                if (in_array($status, ['present', 'absent', 'late', 'leave'], true)) {
                    $dailyTotals[$dateKey][$status]++;
                    $dailyTotals[$dateKey]['total']++;
                }
            }

            $effectivePresent = $pCount + ($lCount * 0.5);
            $attendancePercentage = $markedDays > 0 ? round(($effectivePresent / $markedDays) * 100, 1) : 0;

            return [
                'id'                    => $student->id,
                'registration_no'       => $student->registration_no,
                'student_name'          => $student->student_name,
                'father_name'           => $student->father_name,
                'b_form_no'             => $student->b_form_no,
                'class'                 => $student->class,
                'section'               => $student->section,
                'gender'                => $student->gender,
                'mobile_no'             => $student->mobile_no,
                'photo_url'             => $student->photo_path ? asset('storage/' . $student->photo_path) : null,
                'days'                  => $records,
                'present_count'         => $pCount,
                'absent_count'          => $aCount,
                'late_count'            => $lCount,
                'leave_count'           => $lvCount,
                'marked_days'           => $markedDays,
                'attendance_percentage' => $attendancePercentage,
            ];
        });

        $sectionAvgPercentage = $totalStudents > 0 && $studentRows->isNotEmpty()
            ? round($studentRows->avg('attendance_percentage'), 1)
            : 0;

        return response()->json([
            'session'         => $session,
            'class'           => $class,
            'section'         => $section,
            'month'           => $startOfMonth->format('Y-m'),
            'month_name'      => $startOfMonth->format('F Y'),
            'days_in_month'   => $daysInMonth,
            'days'            => $days,
            'working_days'    => $workingDaysCount,
            'summary'         => [
                'total_students'           => $totalStudents,
                'avg_attendance_rate'      => $sectionAvgPercentage,
                'total_present_instances'  => $totalPresentOverall,
                'total_absent_instances'   => $totalAbsentOverall,
                'total_late_instances'     => $totalLateOverall,
                'total_leave_instances'    => $totalLeaveOverall,
            ],
            'daily_totals'    => $dailyTotals,
            'students'        => $studentRows,
        ]);
    }
}
