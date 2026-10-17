<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use ZipArchive;
use SimpleXMLElement;
use Exception;

class StudentRegisterImportService
{
    /**
     * Import students and photos from a government register .xlsx file.
     *
     * @param string $filePath Absolute path to the .xlsx file
     * @param int|null $userId Optional user ID who initiated the import
     * @param string $session Academic session (e.g. '2026-2027')
     * @return array Import summary stats
     */
    public function import(string $filePath, ?int $userId = null, string $session = '2026-2027'): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found at path: {$filePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Unable to open XLSX file as archive.");
        }

        // 1. Build row-to-image mapping from drawing1.xml and its rels
        $rowToImage = $this->extractImageMapping($zip);

        // 2. Read shared strings if any
        $sharedStrings = $this->extractSharedStrings($zip);

        // 3. Read sheet1.xml
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $zip->close();
            throw new Exception("Could not find xl/worksheets/sheet1.xml in archive.");
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml) {
            $zip->close();
            throw new Exception("Failed to parse sheet XML.");
        }

        Storage::disk('public')->makeDirectory('students/photos');

        $headers = [];
        $totalProcessed = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $photosAttached = 0;
        $classesSeen = [];

        foreach ($xml->sheetData->row as $row) {
            $rowNum = (int) $row['r'];

            // First row is headers
            if ($rowNum === 1) {
                foreach ($row->c as $cell) {
                    $ref = (string) $cell['r'];
                    $col = preg_replace('/[0-9]/', '', $ref);
                    $headers[$col] = trim($this->getCellValue($cell, $sharedStrings));
                }
                continue;
            }

            // Extract row values by column letter
            $rowData = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $col = preg_replace('/[0-9]/', '', $ref);
                $rowData[$col] = trim($this->getCellValue($cell, $sharedStrings));
            }

            // Expected columns from government export:
            // B: Registration No
            // C: Student Name
            // D: Class
            // E: Gender
            // F: Admission Date
            // G: Mobile No
            // H: Father Name
            // I: B-Form Number
            $regNo = $rowData['B'] ?? '';
            $studentName = $rowData['C'] ?? '';

            if (empty($regNo) && empty($studentName)) {
                continue;
            }

            $cleanRegNo = trim($regNo);
            if (empty($cleanRegNo)) {
                continue;
            }

            $className = !empty($rowData['D']) ? trim($rowData['D']) : 'Unassigned';
            $gender = !empty($rowData['E']) ? ucfirst(strtolower(trim($rowData['E']))) : null;
            $admissionDate = null;
            if (!empty($rowData['F'])) {
                $parsedDate = strtotime(trim($rowData['F']));
                if ($parsedDate) {
                    $admissionDate = date('Y-m-d', $parsedDate);
                }
            }
            $mobileNo = !empty($rowData['G']) ? trim($rowData['G']) : null;
            $fatherName = !empty($rowData['H']) ? trim($rowData['H']) : null;
            $bFormNo = !empty($rowData['I']) ? trim($rowData['I']) : null;

            // Handle Photo Extraction
            $photoPath = null;
            if (isset($rowToImage[$rowNum])) {
                $imageZipPath = $rowToImage[$rowNum];
                $imageBinary = $zip->getFromName($imageZipPath);
                if ($imageBinary) {
                    $safeFileStem = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cleanRegNo);
                    $storagePath = "students/photos/{$safeFileStem}.jpeg";
                    Storage::disk('public')->put($storagePath, $imageBinary);
                    $photoPath = $storagePath;
                    $photosAttached++;
                }
            }

            $cleanSession = trim($session) ?: '2026-2027';

            $existingStudent = Student::where('registration_no', $cleanRegNo)
                                      ->where('session', $cleanSession)
                                      ->first();

            $updateData = [
                'student_name'   => $studentName,
                'father_name'    => $fatherName,
                'b_form_no'      => $bFormNo,
                'class'          => $className,
                'session'        => $cleanSession,
                'gender'         => $gender,
                'admission_date' => $admissionDate,
                'mobile_no'      => $mobileNo,
                'status'         => 'active',
            ];

            if ($photoPath) {
                $updateData['photo_path'] = $photoPath;
            } elseif ($existingStudent && $existingStudent->photo_path) {
                $updateData['photo_path'] = $existingStudent->photo_path;
            } else {
                // Reuse existing photo from a prior session if available
                $priorStudent = Student::where('registration_no', $cleanRegNo)->whereNotNull('photo_path')->first();
                if ($priorStudent) {
                    $updateData['photo_path'] = $priorStudent->photo_path;
                }
            }

            if ($userId) {
                $updateData['created_by'] = $userId;
            }

            if ($existingStudent) {
                $existingStudent->update($updateData);
                $updatedCount++;
            } else {
                $updateData['registration_no'] = $cleanRegNo;
                Student::create($updateData);
                $createdCount++;
            }

            $totalProcessed++;
            $classesSeen[$className] = ($classesSeen[$className] ?? 0) + 1;
        }

        $zip->close();

        return [
            'session'         => $cleanSession,
            'total_processed' => $totalProcessed,
            'created_count'   => $createdCount,
            'updated_count'   => $updatedCount,
            'photos_attached' => $photosAttached,
            'classes_summary' => $classesSeen,
        ];
    }

    /**
     * Map Excel row numbers (1-indexed) to image zip paths using DOMDocument.
     */
    protected function extractImageMapping(ZipArchive $zip): array
    {
        $mapping = [];

        $relsXml = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        $drawingXml = $zip->getFromName('xl/drawings/drawing1.xml');

        if (!$relsXml || !$drawingXml) {
            return $mapping;
        }

        // 1. Parse .rels to get rId -> Target
        $relsDom = new \DOMDocument();
        @$relsDom->loadXML($relsXml);
        $ridMap = [];
        foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            $ridMap[$id] = str_replace('../', 'xl/', $target);
        }

        // 2. Parse drawing1.xml to map row number to rId
        $drawDom = new \DOMDocument();
        @$drawDom->loadXML($drawingXml);
        $anchors = $drawDom->getElementsByTagName('twoCellAnchor');

        foreach ($anchors as $anchor) {
            $from = $anchor->getElementsByTagName('from');
            if ($from->length === 0) continue;
            $rows = $from->item(0)->getElementsByTagName('row');
            if ($rows->length === 0) continue;

            // 0-indexed row: row 1 is Excel row 2
            $excelRow = (int) $rows->item(0)->textContent + 1;

            $blips = $anchor->getElementsByTagName('blip');
            if ($blips->length === 0) continue;

            $rId = $blips->item(0)->getAttribute('r:embed');
            if ($rId && isset($ridMap[$rId])) {
                $mapping[$excelRow] = $ridMap[$rId];
            }
        }

        return $mapping;
    }

    /**
     * Extract shared strings array.
     */
    protected function extractSharedStrings(ZipArchive $zip): array
    {
        $strings = [];
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if (!$xmlContent) {
            return $strings;
        }

        $xml = simplexml_load_string($xmlContent);
        if (!$xml) {
            return $strings;
        }

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $strings[] = '';
            }
        }

        return $strings;
    }

    /**
     * Read cell value supporting inline strings, shared strings, and plain values.
     */
    protected function getCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr' || isset($cell->is->t)) {
            return (string) $cell->is->t;
        }

        $val = isset($cell->v) ? (string) $cell->v : '';

        if ($type === 's' && is_numeric($val)) {
            $index = (int) $val;
            return $sharedStrings[$index] ?? '';
        }

        return $val;
    }
}
