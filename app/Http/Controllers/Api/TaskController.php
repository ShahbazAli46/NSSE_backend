<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\Task;
use Illuminate\Http\Request;
use Pusher\Pusher;

class TaskController extends Controller
{
    /**
     * Get tasks list (Assigned to me vs Assigned by me).
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $view = $request->get('view', 'assigned_to_me'); // 'assigned_to_me' or 'assigned_by_me'

        $query = Task::with([
            'creator:id,name,role',
            'assignee:id,name,role',
            'message:id,message,type,sender_id,recipient_id,created_at',
            'comments.user:id,name,role',
        ]);

        if ($view === 'assigned_by_me') {
            $query->where('creator_id', $currentUser->id)->whereNull('creator_deleted_at');
        } else {
            $query->where('assignee_id', $currentUser->id)->whereNull('assignee_deleted_at');
        }

        $tasks = $query->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->latest('created_at')
            ->get();

        $counts = [
            'assigned_to_me' => Task::where('assignee_id', $currentUser->id)
                ->whereNull('assignee_deleted_at')
                ->where('status', '!=', 'completed')
                ->count(),
            'assigned_by_me' => Task::where('creator_id', $currentUser->id)
                ->whereNull('creator_deleted_at')
                ->where('status', '!=', 'completed')
                ->count(),
            'total_completed' => Task::where(function ($q) use ($currentUser) {
                $q->where(fn($sub) => $sub->where('assignee_id', $currentUser->id)->whereNull('assignee_deleted_at'))
                  ->orWhere(fn($sub) => $sub->where('creator_id', $currentUser->id)->whereNull('creator_deleted_at'));
            })
            ->where('status', 'completed')
            ->count(),
        ];

        return response()->json([
            'tasks' => $tasks,
            'counts' => $counts,
        ]);
    }

    /**
     * Create a task (standalone or directly from a chat message).
     */
    public function store(Request $request)
    {
        $currentUser = $request->user();

        $request->validate([
            'creator_id' => 'nullable|exists:users,id',
            'assignee_id' => 'required|exists:users,id',
            'title' => 'required|string|max:1000',
            'priority' => 'nullable|in:normal,urgent',
            'due_date' => 'nullable|date',
            'message_id' => 'nullable|exists:direct_messages,id',
        ]);

        $creatorId = $request->creator_id ? (int) $request->creator_id : $currentUser->id;

        $task = Task::create([
            'creator_id' => $creatorId,
            'assignee_id' => (int) $request->assignee_id,
            'message_id' => $request->message_id ? (int) $request->message_id : null,
            'title' => $request->title,
            'priority' => $request->input('priority', 'normal'),
            'due_date' => $request->due_date,
            'status' => 'pending',
            'completed_at' => null,
        ]);

        // If born from a chat message, link it
        if ($request->message_id) {
            $message = DirectMessage::find($request->message_id);
            if ($message) {
                $message->update([
                    'is_task' => true,
                    'task_id' => $task->id,
                ]);

                // Notify chat channel that message was converted to task
                $this->broadcastTaskUpdate($task, $message);
            }
        }

        $task->load(['creator:id,name,role', 'assignee:id,name,role', 'message', 'comments.user:id,name,role']);

        return response()->json($task, 201);
    }

    /**
     * Update task status (e.g. pending <-> in_progress <-> completed).
     */
    public function updateStatus(Request $request, $id)
    {
        $currentUser = $request->user();

        $task = Task::where(function ($q) use ($currentUser) {
            $q->where('assignee_id', $currentUser->id)->orWhere('creator_id', $currentUser->id);
        })->findOrFail($id);

        $request->validate([
            'status' => 'required|in:pending,in_progress,completed',
        ]);

        $status = $request->status;
        $task->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        $task->load(['creator:id,name,role', 'assignee:id,name,role', 'message', 'comments.user:id,name,role']);

        if ($task->message_id) {
            $message = DirectMessage::find($task->message_id);
            if ($message) {
                $this->broadcastTaskUpdate($task, $message);
            }
        }

        return response()->json($task);
    }

    /**
     * Add a mini chat/activity comment under a task.
     */
    public function addComment(Request $request, $id)
    {
        $currentUser = $request->user();

        $task = Task::where(function ($q) use ($currentUser) {
            $q->where('assignee_id', $currentUser->id)->orWhere('creator_id', $currentUser->id);
        })->findOrFail($id);

        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $comment = $task->comments()->create([
            'user_id' => $currentUser->id,
            'message' => $request->message,
        ]);

        $comment->load('user:id,name,role');

        // Broadcast task update so discussion thread updates live
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

                $channels = ["user-{$task->assignee_id}"];
                if ($task->creator_id !== $task->assignee_id) {
                    $channels[] = "user-{$task->creator_id}";
                }

                $pusher->trigger($channels, 'task-comment-added', [
                    'task_id' => $task->id,
                    'comment' => $comment,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Pusher task comment broadcast error: ' . $e->getMessage());
        }

        return response()->json($comment, 201);
    }

    /**
     * Delete a task for the current user (per-user soft deletion).
     */
    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $task = Task::where(function ($q) use ($currentUser) {
            $q->where('assignee_id', $currentUser->id)->orWhere('creator_id', $currentUser->id);
        })->findOrFail($id);

        if ($currentUser->id === $task->creator_id) {
            $task->creator_deleted_at = now();
        }

        if ($currentUser->id === $task->assignee_id) {
            $task->assignee_deleted_at = now();
        }

        $task->save();

        $task->load(['creator:id,name,role', 'assignee:id,name,role', 'message', 'comments.user:id,name,role']);

        // Broadcast to the other party so their screen immediately reflects the deletion notice
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

                $channels = ["user-{$task->assignee_id}"];
                if ($task->creator_id !== $task->assignee_id) {
                    $channels[] = "user-{$task->creator_id}";
                }

                $pusher->trigger($channels, 'task-updated', $task);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Pusher task delete broadcast error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Task removed from your desk.',
            'task' => $task,
        ]);
    }

    /**
     * Broadcast task update via Pusher.
     */
    protected function broadcastTaskUpdate(Task $task, DirectMessage $message)
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

                $channels = ["user-{$task->assignee_id}"];
                if ($task->creator_id !== $task->assignee_id) {
                    $channels[] = "user-{$task->creator_id}";
                }

                $pusher->trigger($channels, 'task-updated', $task);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Pusher task update broadcast error: ' . $e->getMessage());
        }
    }
}
