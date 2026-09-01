<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Pusher\Pusher;

class ChatController extends Controller
{
    /**
     * Get all active staff contacts with unread badges and latest message snippet.
     */
    public function contacts(Request $request)
    {
        $currentUser = $request->user();

        $users = User::where('is_active', true)
            ->where('id', '!=', $currentUser->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $contacts = $users->map(function ($u) use ($currentUser) {
            $unreadCount = DirectMessage::where('sender_id', $u->id)
                ->where('recipient_id', $currentUser->id)
                ->whereNull('read_at')
                ->count();

            $lastMessage = DirectMessage::where(function ($q) use ($currentUser, $u) {
                $q->where('sender_id', $currentUser->id)->where('recipient_id', $u->id);
            })->orWhere(function ($q) use ($currentUser, $u) {
                $q->where('sender_id', $u->id)->where('recipient_id', $currentUser->id);
            })
            ->latest('created_at')
            ->first(['id', 'message', 'type', 'created_at', 'sender_id']);

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage ? [
                    'message' => $lastMessage->type === 'voice' 
                        ? '🎙️ Voice message' 
                        : ($lastMessage->type === 'document' || $lastMessage->type === 'image' 
                            ? '📎 Attachment' 
                            : $lastMessage->message),
                    'type' => $lastMessage->type,
                    'created_at' => $lastMessage->created_at->toIso8601String(),
                    'is_mine' => $lastMessage->sender_id === $currentUser->id,
                ] : null,
            ];
        });

        // Sort contacts: ones with latest messages first, then alphabetical
        $sorted = $contacts->sortByDesc(function ($c) {
            return $c['last_message']['created_at'] ?? '1970-01-01T00:00:00Z';
        })->values();

        return response()->json($sorted);
    }

    /**
     * Get message thread with a specific contact and mark unread as read.
     */
    public function messages(Request $request, $recipientId)
    {
        $currentUser = $request->user();

        // Mark incoming messages as read
        DirectMessage::where('sender_id', $recipientId)
            ->where('recipient_id', $currentUser->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = DirectMessage::with([
            'sender:id,name,role',
            'task:id,title,status,priority,assignee_id,creator_id',
        ])
        ->where(function ($q) use ($currentUser, $recipientId) {
            $q->where('sender_id', $currentUser->id)->where('recipient_id', $recipientId);
        })
        ->orWhere(function ($q) use ($currentUser, $recipientId) {
            $q->where('sender_id', $recipientId)->where('recipient_id', $currentUser->id);
        })
        ->orderBy('created_at', 'asc')
        ->get();

        return response()->json($messages);
    }

    /**
     * Send a direct message (text, voice note, document, or image).
     */
    public function sendMessage(Request $request)
    {
        $currentUser = $request->user();

        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'type' => 'nullable|in:text,voice,image,document',
            'message' => 'nullable|string|max:5000',
            'duration' => 'nullable|integer',
            'attachment' => 'nullable|file|max:20480', // 20MB limit
        ]);

        $type = $request->input('type', 'text');
        $attachmentUrl = null;
        $attachmentName = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $mime = $file->getMimeType();

            if (str_starts_with($mime, 'audio/') || $type === 'voice') {
                $type = 'voice';
                $path = $file->store('chat_voice', 'public');
            } elseif (str_starts_with($mime, 'image/') || $type === 'image') {
                $type = 'image';
                $path = $file->store('chat_images', 'public');
            } else {
                $type = 'document';
                $path = $file->store('chat_docs', 'public');
            }

            $attachmentUrl = url(Storage::url($path));
        }

        $directMessage = DirectMessage::create([
            'sender_id' => $currentUser->id,
            'recipient_id' => (int) $request->recipient_id,
            'message' => $request->message,
            'type' => $type,
            'attachment_url' => $attachmentUrl,
            'attachment_name' => $attachmentName,
            'duration' => $request->duration ? (int) $request->duration : null,
            'is_task' => false,
            'task_id' => null,
        ]);

        $directMessage->load(['sender:id,name,role', 'task:id,title,status,priority']);

        // Trigger Realtime Delivery via Pusher
        $this->broadcastPusher($directMessage);

        return response()->json($directMessage, 201);
    }

    /**
     * Broadcast new message to recipient and sender channels.
     */
    protected function broadcastPusher(DirectMessage $message)
    {
        try {
            $key = config('broadcasting.connections.pusher.key') ?: env('PUSHER_APP_KEY');
            $secret = config('broadcasting.connections.pusher.secret') ?: env('PUSHER_APP_SECRET');
            $appId = config('broadcasting.connections.pusher.app_id') ?: env('PUSHER_APP_ID');
            $cluster = config('broadcasting.connections.pusher.options.cluster') ?: env('PUSHER_APP_CLUSTER', 'ap3');

            if ($key && $secret && $appId) {
                $pusher = new Pusher($key, $secret, $appId, [
                    'cluster' => $cluster,
                    'useTLS' => true,
                    'timeout' => 2,
                ]);

                // Send to both channels in ONE single HTTP request
                $channels = ["user-{$message->recipient_id}"];
                if ($message->sender_id !== $message->recipient_id) {
                    $channels[] = "user-{$message->sender_id}";
                }

                $pusher->trigger($channels, 'new-direct-message', $message);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Pusher direct message broadcast error: ' . $e->getMessage());
        }
    }
}
