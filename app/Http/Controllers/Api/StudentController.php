<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\SchoolSection;
use App\Services\StudentRegisterImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class StudentController extends Controller
{
    protected $authorizedRoles = ['hr', 'principal', 'office_admin', 'super_admin', 'admin'];

    /**
     * Check if current user is authorized.
     */
    protected function authorizeAccess(Request $request)
    {
        $role = $request->user()?->role;
        if (!in_array($role, $this->authorizedRoles)) {
            abort(403, 'Unauthorized access to Student Registry.');
        }
    }

    /**
     * Get list of unique academic sessions available in the system.
     */
    public function sessions(Request $request)
    {
        $this->authorizeAccess($request);

        $sessions = Student::distinct()
            ->orderBy('session', 'desc')
            ->pluck('session');

        if ($sessions->isEmpty()) {
            $sessions = collect(['2026-2027']);
        }

        return response()->json($sessions);
    }

    /**
     * List students with session filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $query = Student::query();

        // Default to latest session if not specified
        if ($request->filled('session') && $request->session !== 'all') {
            $query->bySession($request->session);
        } else {
            $query->bySession('2026-2027');
        }

        if ($request->filled('class') && $request->class !== 'all') {
            $query->byClass($request->class);
        }

        if ($request->filled('gender') && $request->gender !== 'all') {
            $query->byGender($request->gender);
        }

        if ($request->filled('section') && $request->section !== 'all') {
            $query->bySection($request->section);
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $perPage = $request->input('per_page', 100);
        $students = $query->orderBy('class', 'asc')
                          ->orderBy('student_name', 'asc')
                          ->paginate($perPage);

        return response()->json($students);
    }

    /**
     * Get aggregate student statistics for a session.
     */
    public function stats(Request $request)
    {
        $this->authorizeAccess($request);

        $query = Student::query();
        if ($request->filled('session') && $request->session !== 'all') {
            $query->bySession($request->session);
        } else {
            $query->bySession('2026-2027');
        }

        $totalStudents = (clone $query)->count();
        $withPhotos = (clone $query)->whereNotNull('photo_path')->count();

        $genderBreakdown = (clone $query)->selectRaw('COALESCE(gender, "Unknown") as gender_name, count(*) as count')
            ->groupBy('gender_name')
            ->pluck('count', 'gender_name');

        $classBreakdown = (clone $query)->selectRaw('class, count(*) as count')
            ->groupBy('class')
            ->orderBy('count', 'desc')
            ->pluck('count', 'class');

        return response()->json([
            'total_students'   => $totalStudents,
            'with_photos'      => $withPhotos,
            'gender_breakdown' => $genderBreakdown,
            'class_breakdown'  => $classBreakdown,
        ]);
    }

    /**
     * View single student profile.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeAccess($request);

        $student = Student::with('creator:id,name,role')->findOrFail($id);

        return response()->json($student);
    }

    /**
     * Handle chunked file uploads from browser.
     * Receives 2MB slices and merges into .xlsx upon completion.
     */
    public function uploadChunk(Request $request, StudentRegisterImportService $importService)
    {
        $this->authorizeAccess($request);

        $request->validate([
            'file'         => 'required|file',
            'upload_id'    => 'required|string|max:64',
            'chunk_index'  => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'file_name'    => 'required|string|max:255',
            'session'      => 'nullable|string|max:30',
        ]);

        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $request->upload_id);
        $chunkIndex = (int) $request->chunk_index;
        $totalChunks = (int) $request->total_chunks;
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '', $request->file_name);

        $chunksDir = storage_path("app/chunks/{$uploadId}");
        if (!File::isDirectory($chunksDir)) {
            File::makeDirectory($chunksDir, 0755, true);
        }

        // Save chunk
        $chunkFile = $request->file('file');
        $chunkFile->move($chunksDir, "chunk_{$chunkIndex}");

        // Check if all chunks received
        $receivedCount = count(File::files($chunksDir));

        if ($receivedCount < $totalChunks) {
            return response()->json([
                'status'    => 'uploading',
                'completed' => false,
                'progress'  => round(($receivedCount / $totalChunks) * 100, 1),
                'received'  => $receivedCount,
                'total'     => $totalChunks,
            ]);
        }

        // All chunks arrived! Re-assemble file
        $tempDir = storage_path("app/temp");
        if (!File::isDirectory($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $assembledFilePath = "{$tempDir}/{$uploadId}_{$fileName}";
        $outFile = fopen($assembledFilePath, 'wb');

        if (!$outFile) {
            throw new Exception("Failed to open output file for chunk reassembly.");
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = "{$chunksDir}/chunk_{$i}";
            if (!file_exists($chunkPath)) {
                fclose($outFile);
                throw new Exception("Missing chunk {$i} during reassembly.");
            }
            $inChunk = fopen($chunkPath, 'rb');
            stream_copy_to_stream($inChunk, $outFile);
            fclose($inChunk);
        }
        fclose($outFile);

        // Clean up chunks
        File::deleteDirectory($chunksDir);

        $session = $request->input('session', '2026-2027') ?: '2026-2027';

        // Process assembled Excel file
        try {
            $summary = $importService->import($assembledFilePath, $request->user()->id, $session);

            // Clean up temporary assembled file
            @unlink($assembledFilePath);

            return response()->json([
                'status'    => 'success',
                'completed' => true,
                'message'   => "Import completed! {$summary['total_processed']} students processed, {$summary['photos_attached']} photos extracted.",
                'summary'   => $summary,
            ]);
        } catch (Exception $e) {
            @unlink($assembledFilePath);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to process Excel file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sections for a session and optional class with real-time student counts.
     */
    public function getSections(Request $request)
    {
        $this->authorizeAccess($request);

        $session = $request->input('session', '2026-2027') ?: '2026-2027';
        $class = $request->input('class');

        // Query sections from school_sections table
        $query = SchoolSection::where('session', $session);
        if ($class && $class !== 'all') {
            $query->where('class', $class);
        }
        $sections = $query->orderBy('class', 'asc')->orderBy('name', 'asc')->get();

        // Also discover any distinct sections already assigned to students that might not yet be in school_sections
        $studentSectionsQuery = Student::where('session', $session);
        if ($class && $class !== 'all') {
            $studentSectionsQuery->where('class', $class);
        }
        $existingSections = $studentSectionsQuery->select('class', 'section')
            ->distinct()
            ->whereNotNull('section')
            ->get();

        foreach ($existingSections as $es) {
            if (!$sections->where('class', $es->class)->where('name', $es->section)->first()) {
                $newSection = SchoolSection::firstOrCreate(
                    ['name' => $es->section, 'class' => $es->class, 'session' => $session],
                    ['capacity' => 40, 'created_by' => $request->user()?->id]
                );
                $sections->push($newSection);
            }
        }

        // Calculate student counts for each section
        $studentCounts = Student::where('session', $session)
            ->when($class && $class !== 'all', fn($q) => $q->where('class', $class))
            ->selectRaw('class, section, count(*) as count')
            ->groupBy('class', 'section')
            ->get()
            ->keyBy(fn($item) => $item->class . '_' . $item->section);

        $result = $sections->map(function ($sec) use ($studentCounts) {
            $key = $sec->class . '_' . $sec->name;
            $count = isset($studentCounts[$key]) ? $studentCounts[$key]->count : 0;
            return [
                'id'         => $sec->id,
                'name'       => $sec->name,
                'class'      => $sec->class,
                'session'    => $sec->session,
                'capacity'   => $sec->capacity ?? 40,
                'count'      => $count,
                'created_at' => $sec->created_at,
            ];
        });

        return response()->json($result);
    }

    /**
     * Create a new school section.
     */
    public function storeSection(Request $request)
    {
        $this->authorizeAccess($request);

        $request->validate([
            'name'     => 'required|string|max:50',
            'class'    => 'required|string|max:100',
            'session'  => 'required|string|max:50',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $section = SchoolSection::firstOrCreate(
            [
                'name'    => trim($request->name),
                'class'   => trim($request->class),
                'session' => trim($request->session),
            ],
            [
                'capacity'   => $request->capacity ?? 40,
                'created_by' => $request->user()?->id,
            ]
        );

        $count = Student::where('session', $section->session)
            ->where('class', $section->class)
            ->where('section', $section->name)
            ->count();

        return response()->json([
            'id'         => $section->id,
            'name'       => $section->name,
            'class'      => $section->class,
            'session'    => $section->session,
            'capacity'   => $section->capacity ?? 40,
            'count'      => $count,
            'message'    => "Section {$section->name} created for class {$section->class}.",
        ], 201);
    }

    /**
     * Delete a school section if no students are assigned.
     */
    public function destroySection(Request $request, $id)
    {
        $this->authorizeAccess($request);

        $section = SchoolSection::findOrFail($id);

        $studentCount = Student::where('session', $section->session)
            ->where('class', $section->class)
            ->where('section', $section->name)
            ->count();

        if ($studentCount > 0) {
            return response()->json([
                'message' => "Cannot delete Section {$section->name} because {$studentCount} students are currently assigned to it. Please reassign them first.",
            ], 422);
        }

        $section->delete();

        return response()->json([
            'message' => "Section {$section->name} deleted successfully.",
        ]);
    }

    /**
     * Bulk assign an array of student IDs to a target section.
     */
    public function bulkAssignSection(Request $request)
    {
        $this->authorizeAccess($request);

        $request->validate([
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'required|integer|exists:students,id',
            'section'     => 'required|string|max:50',
        ]);

        $sectionName = trim($request->section);
        $studentIds = $request->student_ids;

        // Fetch students to know their class and session
        $students = Student::whereIn('id', $studentIds)->get();
        if ($students->isEmpty()) {
            return response()->json(['message' => 'No matching students found.'], 404);
        }

        // Update students
        $updatedCount = Student::whereIn('id', $studentIds)->update(['section' => $sectionName]);

        // Auto-register section in school_sections for each distinct class & session
        $grouped = $students->groupBy(fn($s) => $s->session . '__' . $s->class);
        foreach ($grouped as $group) {
            $first = $group->first();
            SchoolSection::firstOrCreate(
                [
                    'name'    => $sectionName,
                    'class'   => $first->class,
                    'session' => $first->session,
                ],
                [
                    'capacity'   => 40,
                    'created_by' => $request->user()?->id,
                ]
            );
        }

        return response()->json([
            'message'       => "Successfully assigned {$updatedCount} students to Section {$sectionName}.",
            'updated_count' => $updatedCount,
            'section'       => $sectionName,
        ]);
    }
}
