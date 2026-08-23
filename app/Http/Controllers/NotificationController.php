<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * In-app notification centre.
 *
 * Notifications are delivered on the `database` channel and pushed live over
 * Pusher on the private `App.Models.User.{id}` channel (see routes/channels.php).
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(25);

        return view('notification.index', ['notifications' => $notifications]);
    }

    /**
     * Unread count for the header badge (polled, or refreshed by Pusher).
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Open a notification: mark it read, then follow its link if it has one.
     */
    public function show(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        $target = $notification->data['url'] ?? null;

        return $target ? redirect($target) : back();
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', $this->ok(__('lang_v1.updated_successfully')));
    }
}
