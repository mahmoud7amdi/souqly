<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Print queue API for the local print agent.
 *
 * Flow: the browser POSTs a job (same-origin, session-authenticated, see
 * web.php) → the agent polls `pending` with its location token → prints →
 * reports back via `complete`.
 *
 * The agent runs on the shop LAN and cannot hold a user session, so it
 * authenticates with a per-location shared token. Requests without a valid
 * token are refused, which stops an outsider enumerating other shops' jobs.
 */
class PrintQueueController extends Controller
{
    /**
     * Jobs waiting to be printed at the caller's location.
     */
    public function pending(Request $request): JsonResponse
    {
        $locationId = $this->authenticateAgent($request);

        $jobs = PrintJob::where('location_id', $locationId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(20)
            ->get(['id', 'payload', 'created_at']);

        // Claim them so a second agent instance cannot print the same job.
        if ($jobs->isNotEmpty()) {
            PrintJob::whereIn('id', $jobs->pluck('id'))->update(['status' => 'printing']);
        }

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
        ]);
    }

    /**
     * Mark a job done or failed.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $locationId = $this->authenticateAgent($request);

        $validated = $request->validate([
            'status' => 'required|in:done,failed',
            'error_message' => 'nullable|string|max:255',
        ]);

        $job = PrintJob::where('id', $id)
            ->where('location_id', $locationId)
            ->firstOrFail();

        $job->status = $validated['status'];
        $job->error_message = $validated['error_message'] ?? null;
        $job->save();

        if ($job->status === 'failed') {
            Log::warning('Print job failed.', [
                'job' => $job->id,
                'location' => $locationId,
                'error' => $job->error_message,
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Drop finished jobs older than a day so the table stays small.
     */
    public function cleanup(Request $request): JsonResponse
    {
        $locationId = $this->authenticateAgent($request);

        $deleted = PrintJob::where('location_id', $locationId)
            ->whereIn('status', ['done', 'failed'])
            ->where('created_at', '<', now()->subDay())
            ->delete();

        // Jobs stuck in `printing` for over an hour mean the agent died
        // mid-print; return them to the queue rather than losing them.
        $requeued = PrintJob::where('location_id', $locationId)
            ->where('status', 'printing')
            ->where('updated_at', '<', now()->subHour())
            ->update(['status' => 'pending']);

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
            'requeued' => $requeued,
        ]);
    }

    /**
     * Resolve the calling agent's location from its token.
     *
     * The token is `location_id:sha256(location_id . APP_KEY)`, generated for
     * the shop when the agent is set up. Compared with hash_equals so the
     * check is not timing-attackable.
     */
    protected function authenticateAgent(Request $request): int
    {
        $token = (string) ($request->header('X-Print-Token') ?? $request->input('token', ''));

        if (! str_contains($token, ':')) {
            abort(401, 'Invalid print agent token.');
        }

        [$locationId, $signature] = explode(':', $token, 2);

        if (! ctype_digit($locationId)) {
            abort(401, 'Invalid print agent token.');
        }

        if (! hash_equals(static::signatureFor((int) $locationId), $signature)) {
            abort(401, 'Invalid print agent token.');
        }

        return (int) $locationId;
    }

    /**
     * The signature half of a location's agent token.
     */
    public static function signatureFor(int $locationId): string
    {
        return hash_hmac('sha256', 'print-agent:'.$locationId, (string) config('app.key'));
    }

    /**
     * Full token to hand to the agent when setting up a shop.
     */
    public static function tokenFor(int $locationId): string
    {
        return $locationId.':'.static::signatureFor($locationId);
    }
}
