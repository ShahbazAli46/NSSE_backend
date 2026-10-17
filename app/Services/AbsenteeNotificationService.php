<?php

namespace App\Services;

use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Domain Service for Absentee Student WhatsApp Notifications.
 *
 * Prepares absentee student rosters and personalized templates, then delegates
 * batch dispatching and anti-ban throttling to the central WhatsAppService.
 */
class AbsenteeNotificationService
{
    protected WhatsAppService $whatsapp;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
    }

    /**
     * Default bilingual absence notification template.
     */
    public function getDefaultTemplate(): string
    {
        return "{محترم والدین|عزت مآب والدین|گرامی قدر والدین}،\n"
             . "آپ کا بچہ/بچی *{student_name}* ولد *{father_name}* (کلاس: *{class} - سیکشن {section}*) آج بروز *{date}* سکول سے غیر حاضر ہے۔\n"
             . "برائے مہربانی غیر حاضری کی وجہ سکول انتظامیہ کو مطلع فرمائیں۔\n\n"
             . "{Dear Parent|Respected Parent},\n"
             . "Your child *{student_name}* ({class}-{section}) is absent from school today (*{date}*).\n"
             . "Please notify the school administration of the reason for absence.\n\n"
             . "{شکریہ / Regards|نیک تمنائیں / Best regards},\n"
             . "*{school_name}*";
    }

    /**
     * Render message with student and date placeholders.
     */
    public function renderMessage(StudentAttendance $attendance, ?string $customTemplate = null): string
    {
        $template = $customTemplate ?: $this->getDefaultTemplate();
        $student = $attendance->student;

        $dateFormatted = Carbon::parse($attendance->date)->format('d-M-Y');
        $studentName = $student?->student_name ?? 'Student';
        $fatherName = $student?->father_name ?? '—';
        $class = $attendance->class ?? $student?->class ?? '';
        $section = $attendance->section ?? $student?->section ?? 'A';
        $schoolName = 'Nawaz Shareef School of Excellence';

        return $this->whatsapp->renderTemplate($template, [
            'student_name' => $studentName,
            'father_name'  => $fatherName,
            'class'        => $class,
            'section'      => $section,
            'date'         => $dateFormatted,
            'school_name'  => $schoolName,
        ]);
    }

    /**
     * Process and dispatch a batch of absentee WhatsApp messages using the central Anti-Ban engine.
     *
     * @param array $attendanceIds
     * @param string|null $customTemplate
     * @param int|null $dispatchedByUserId
     * @return array Summary of dispatch results
     */
    public function processBatch(array $attendanceIds, ?string $customTemplate = null, ?int $dispatchedByUserId = null): array
    {
        $attendances = StudentAttendance::whereIn('id', $attendanceIds)
            ->where('status', 'absent')
            ->with(['student'])
            ->get();

        // 1. Prepare items for the central WhatsApp batch dispatcher
        $items = [];
        $attendanceMap = [];

        foreach ($attendances as $attendance) {
            $student = $attendance->student;
            $phone = $student?->mobile_no ?? '';
            $message = $this->renderMessage($attendance, $customTemplate);

            $items[] = [
                'id'            => $attendance->id,
                'phone'         => $phone,
                'message'       => $message,
                'student_id'    => $attendance->student_id,
                'student_name'  => $student?->student_name,
            ];

            $attendanceMap[$attendance->id] = $attendance;
        }

        // 2. Delegate to central WhatsAppService with progress callback for DB updates
        $summary = $this->whatsapp->dispatchBatchWithAntiBan(
            $items,
            function (string $status, array $item, array $result) use ($attendanceMap) {
                $attId = $item['id'];
                if (isset($attendanceMap[$attId])) {
                    $att = $attendanceMap[$attId];

                    if ($status === 'sent') {
                        $att->update([
                            'whatsapp_status'  => 'sent',
                            'whatsapp_msg_id'  => $result['whatsapp_msg_id'] ?? null,
                            'whatsapp_sent_at' => Carbon::now(),
                            'whatsapp_error'   => null,
                        ]);
                    } elseif ($status === 'failed') {
                        $att->update([
                            'whatsapp_status' => 'failed',
                            'whatsapp_error'  => substr($result['error'] ?? 'WhatsApp dispatch failure', 0, 250),
                        ]);
                    } elseif ($status === 'skipped') {
                        $att->update([
                            'whatsapp_status' => 'skipped',
                            'whatsapp_error'  => $result['error'] ?? 'Skipped (invalid phone number)',
                        ]);
                    }
                }
            }
        );

        $summary['dispatched_by'] = $dispatchedByUserId;
        return $summary;
    }

    /**
     * Send a single instant WhatsApp alert for a specific absentee.
     */
    public function sendSingle(StudentAttendance $attendance, ?string $customTemplate = null, ?int $dispatchedByUserId = null): array
    {
        $student = $attendance->student;
        $rawPhone = $student?->mobile_no;

        if (empty($rawPhone) || !$this->whatsapp->isValidNumber($rawPhone)) {
            $errorMsg = empty($rawPhone) ? 'No phone number available.' : "Invalid phone number: '{$rawPhone}'";
            $attendance->update([
                'whatsapp_status' => 'skipped',
                'whatsapp_error'  => $errorMsg,
            ]);
            return [
                'success' => false,
                'error'   => $errorMsg,
            ];
        }

        $message = $this->renderMessage($attendance, $customTemplate);
        $result = $this->whatsapp->sendMessage($rawPhone, $message);

        if ($result['success']) {
            $attendance->update([
                'whatsapp_status'  => 'sent',
                'whatsapp_msg_id'  => $result['whatsapp_msg_id'] ?? null,
                'whatsapp_sent_at' => Carbon::now(),
                'whatsapp_error'   => null,
            ]);
            return [
                'success'         => true,
                'message'         => 'WhatsApp message delivered successfully!',
                'whatsapp_msg_id' => $result['whatsapp_msg_id'] ?? null,
            ];
        } else {
            $errorMsg = $result['error'] ?? 'Failed to send WhatsApp message.';
            $attendance->update([
                'whatsapp_status' => 'failed',
                'whatsapp_error'  => substr($errorMsg, 0, 250),
            ]);
            return [
                'success' => false,
                'error'   => $errorMsg,
            ];
        }
    }
}
