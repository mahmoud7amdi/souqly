<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
| Realtime notification channels (Pusher). Every channel is scoped to a
| tenant so one business can never listen in on another's traffic.
*/

/**
 * Per-user notification stream (bell icon, toasts).
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return (int) $user->id === $id;
});

/**
 * Business-wide stream: new sales, low stock alerts, payments received.
 */
Broadcast::channel('business.{businessId}', function (User $user, int $businessId) {
    return (int) $user->business_id === $businessId;
});

/**
 * Per-location stream: POS screens, kitchen/station displays, print agent.
 */
Broadcast::channel('location.{locationId}', function (User $user, int $locationId) {
    if (! $user->can('access_all_locations') && ! $user->can('location.'.$locationId)) {
        return false;
    }

    return \App\Models\BusinessLocation::withoutGlobalScope(\App\Scopes\BusinessScope::class)
        ->where('id', $locationId)
        ->where('business_id', $user->business_id)
        ->exists();
});

/**
 * Print queue stream for a location's local print agent.
 */
Broadcast::channel('print-queue.{locationId}', function (User $user, int $locationId) {
    return $user->can('access_printers')
        && \App\Models\BusinessLocation::withoutGlobalScope(\App\Scopes\BusinessScope::class)
            ->where('id', $locationId)
            ->where('business_id', $user->business_id)
            ->exists();
});
