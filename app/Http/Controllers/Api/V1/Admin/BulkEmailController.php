<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\PlatformAnnouncementMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BulkEmailController extends ApiController
{
    public const MAX_RECIPIENTS = 200;

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'all' => ['sometimes', 'boolean'],
            'user_ids' => ['required_unless:all,true', 'array', 'max:'.self::MAX_RECIPIENTS],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $sendToAll = (bool) ($validated['all'] ?? false);
        $query = User::query()->orderBy('id');
        if (! $sendToAll) {
            $query->whereIn('id', $validated['user_ids'] ?? []);
        }

        $users = $query->get(['id', 'name', 'email']);

        if ($users->isEmpty()) {
            return $this->errorResponse('Select at least one user.', 422);
        }

        if ($users->count() > self::MAX_RECIPIENTS) {
            return $this->errorResponse('Select '.self::MAX_RECIPIENTS.' users or fewer for one send.', 422);
        }

        $sent = 0;
        $queued = [];
        $failed = [];

        foreach ($users as $user) {
            $subject = str_replace('{{name}}', $user->name, $validated['subject']);
            $body = str_replace('{{name}}', $user->name, $validated['body']);

            try {
                $message = Mail::to($user->email)->send(new PlatformAnnouncementMail($user->name, $subject, $body));
                $sent++;
                $queued[] = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'queue_id' => $message?->getMessageId(),
                ];
            } catch (Throwable $e) {
                Log::error('Admin bulk email failed.', [
                    'admin_id' => $request->user()?->id,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = ['id' => $user->id, 'email' => $user->email];
            }
        }

        Log::info('Admin bulk email finished.', [
            'admin_id' => $request->user()?->id,
            'all' => $sendToAll,
            'sent' => $sent,
            'failed' => count($failed),
            'failed_emails' => array_column($failed, 'email'),
            'subject' => $validated['subject'],
        ]);

        $skipped = count($failed);
        $message = $skipped === 0
            ? "Email queued for {$sent} user".($sent === 1 ? '' : 's').'.'
            : "Email queued for {$sent} user".($sent === 1 ? '' : 's').". {$skipped} could not be sent.";

        return $this->successResponse($message, [
            'sent' => $sent,
            'queued' => $queued,
            'failed' => $failed,
        ]);
    }
}
