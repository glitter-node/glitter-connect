<?php

namespace Glitter\Connect\Http\Controllers;

use Glitter\Connect\Services\ConnectionService;
use Glitter\Connect\Services\CredentialService;
use Glitter\Connect\Services\InboundService;
use Glitter\Connect\Exceptions\InboundConflictException;
use Glitter\Connect\Services\MonitoringService;
use Glitter\Connect\Services\OutboundTargetGuard;
use Glitter\Connect\Services\ReplayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ConnectController
{
    public function overview(MonitoringService $monitoring): mixed { return response()->json(['data' => $monitoring->overview()]); }
    public function connections(Request $request, ConnectionService $service): mixed { return response()->json(['data' => $service->list((int) $request->query('per_page', 25))]); }
    public function connection(Request $request, int $id): mixed { return response()->json(['data' => $this->rowOrFail('glitter_connect_connections', $id, ['id', 'name', 'provider', 'status', 'signature_required', 'created_at', 'updated_at'])]); }
    public function createConnection(Request $request, ConnectionService $service): mixed
    {
        $request->merge(['signature_required' => $request->input('signature_required', true)]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:80'],
            'signature_required' => ['required', 'boolean'],
            'secret' => ['nullable', 'string', 'max:4096', Rule::requiredIf(fn (): bool => $request->boolean('signature_required'))],
            'config' => ['nullable', 'array'],
        ]);
        $data['signature_required'] = $request->boolean('signature_required');
        return response()->json(['data' => $service->create($data)], 201);
    }
    public function updateConnection(Request $request, int $id, ConnectionService $service): mixed
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'config' => ['sometimes', 'array']]);
        if ($data === []) {
            $current = DB::table('glitter_connect_connections')->where('id', $id)->first(['status']);
            abort_if($current === null, 404);
            $data['status'] = $current->status === 'active' ? 'inactive' : 'active';
        }
        return response()->json(['data' => $service->update($id, $data)]);
    }
    public function deleteConnection(int $id, ConnectionService $service): mixed { $service->delete($id); return response()->json(['data' => ['deleted' => true]]); }
    public function rotateSecret(Request $request, int $id, CredentialService $credentials): mixed
    {
        abort_unless(DB::table('glitter_connect_connections')->where('id', $id)->exists(), 404);
        $secret = Str::random(64);
        $credentials->put($id, 'api_secret', $secret);
        return response()->json(['data' => ['secret' => $secret]]);
    }
    public function testConnection(int $id): mixed
    {
        abort_unless(DB::table('glitter_connect_connections')->where('id', $id)->exists(), 404);
        return response()->json(['data' => ['status' => 'configured']]);
    }
    public function endpoints(Request $request): mixed { return response()->json(['data' => DB::table('glitter_connect_endpoints')->select(['id', 'connection_id', 'endpoint_key', 'name', 'signature_version', 'status', 'max_payload_bytes', 'created_at'])->orderByDesc('id')->paginate(min(100, max(1, (int) $request->query('per_page', 25)) ))]); }
    public function createEndpoint(Request $request): mixed
    {
        $data = $request->validate(['connection_id' => ['nullable', 'integer', 'exists:glitter_connect_connections,id'], 'name' => ['required', 'string', 'max:120'], 'max_payload_bytes' => ['nullable', 'integer', 'min:1024', 'max:262144']]);
        $key = Str::random(48);
        $secret = Str::random(64);
        $id = DB::table('glitter_connect_endpoints')->insertGetId(['connection_id' => $data['connection_id'] ?? null, 'endpoint_key' => $key, 'name' => $data['name'], 'secret_encrypted' => Crypt::encryptString($secret), 'signature_version' => 'v1', 'status' => 'active', 'max_payload_bytes' => $data['max_payload_bytes'] ?? 262144, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['id' => $id, 'endpoint_key' => $key, 'signature_version' => 'v1', 'secret' => $secret]], 201);
    }
    public function updateEndpoint(Request $request, int $id): mixed
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'max_payload_bytes' => ['sometimes', 'integer', 'min:1024', 'max:262144']]);
        if ($data === []) {
            $current = $this->rowOrFail('glitter_connect_endpoints', $id, ['id', 'status']);
            $data['status'] = $current->status === 'active' ? 'inactive' : 'active';
        }
        DB::table('glitter_connect_endpoints')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => $this->rowOrFail('glitter_connect_endpoints', $id, ['id', 'connection_id', 'endpoint_key', 'name', 'signature_version', 'status', 'max_payload_bytes', 'created_at'])]);
    }
    public function subscriptions(Request $request): mixed { return response()->json(['data' => DB::table('glitter_connect_subscriptions')->select(['id', 'connection_id', 'event_type', 'target_url', 'status', 'outbound_signature_version', 'created_at'])->orderByDesc('id')->paginate(min(100, max(1, (int) $request->query('per_page', 25)) ))]); }
    public function createSubscription(Request $request, OutboundTargetGuard $guard): mixed
    {
        $data = $request->validate([
            'connection_id' => ['required', 'integer', 'exists:glitter_connect_connections,id'],
            'event_type' => ['required', 'string', 'max:160'],
            'target_url' => ['required', 'url', 'max:2048'],
            'outbound_signature_version' => ['sometimes', Rule::in(['v0', 'v1'])],
        ]);
        $guard->resolvePublicTarget($data['target_url']);
        $id = DB::table('glitter_connect_subscriptions')->insertGetId(['connection_id' => $data['connection_id'], 'event_type' => $data['event_type'], 'target_url' => $data['target_url'], 'status' => 'active', 'outbound_signature_version' => $data['outbound_signature_version'] ?? 'v1', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => DB::table('glitter_connect_subscriptions')->find($id)], 201);
    }
    public function updateSubscription(Request $request, int $id, OutboundTargetGuard $guard): mixed
    {
        $data = $request->validate(['event_type' => ['sometimes', 'string', 'max:160'], 'target_url' => ['sometimes', 'url', 'max:2048'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'outbound_signature_version' => ['sometimes', Rule::in(['v0', 'v1'])]]);
        if ($data === []) {
            $current = $this->rowOrFail('glitter_connect_subscriptions', $id, ['id', 'status']);
            $data['status'] = $current->status === 'active' ? 'inactive' : 'active';
        }
        if (isset($data['target_url'])) $guard->resolvePublicTarget($data['target_url']);
        DB::table('glitter_connect_subscriptions')->where('id', $id)->update($data + ['updated_at' => now()]);
        return response()->json(['data' => $this->rowOrFail('glitter_connect_subscriptions', $id)]);
    }
    public function events(Request $request): mixed { return response()->json(['data' => DB::table('glitter_connect_events')->select(['id', 'event_id', 'event_type', 'source', 'subject', 'received_at', 'status'])->orderByDesc('id')->paginate(min(100, max(1, (int) $request->query('per_page', 25)) ))]); }
    public function event(int $id): mixed { return response()->json(['data' => $this->rowOrFail('glitter_connect_events', $id)]); }
    public function deliveries(Request $request): mixed { return response()->json(['data' => DB::table('glitter_connect_deliveries')->select(['id', 'event_id', 'subscription_id', 'status', 'attempt_count', 'next_attempt_at', 'response_code', 'last_error', 'delivered_at', 'updated_at'])->orderByDesc('id')->paginate(min(100, max(1, (int) $request->query('per_page', 25)) ))]); }
    public function delivery(int $id): mixed { return response()->json(['data' => ['delivery' => $this->rowOrFail('glitter_connect_deliveries', $id), 'attempts' => DB::table('glitter_connect_attempts')->where('delivery_id', $id)->orderBy('attempt_number')->get()]]); }
    public function replay(Request $request, int $id, ReplayService $replay): mixed { $replay->replay($id, $request->user()?->getAuthIdentifier()); return response()->json(['data' => ['queued' => true]]); }
    public function metrics(MonitoringService $monitoring): mixed { return response()->json(['data' => $monitoring->overview()]); }
    public function settings(): mixed { return response()->json(['data' => module_setting('glitter-connect', 'delivery', ['retention_days' => 90, 'max_attempts' => 12])]); }
    public function updateSettings(Request $request): mixed
    {
        $data = $request->validate(['retention_days' => ['required', 'integer', 'min:1', 'max:3650'], 'max_attempts' => ['required', 'integer', 'min:1', 'max:30']]);
        $saved = app(\App\Services\ModuleSettingsService::class)->save('glitter-connect', ['delivery' => $data]);
        abort_unless($saved, 500);
        return response()->json(['data' => $data]);
    }
    public function inbound(Request $request, string $endpointKey, InboundService $inbound): mixed
    {
        try {
            $id = $inbound->accept($endpointKey, $request->getContent(), (string) $request->header('X-Glitter-Signature'), (string) $request->header('X-Glitter-Timestamp'), (string) $request->header('Idempotency-Key'));
            return response()->json(['accepted' => true, 'event_id' => $id], 202);
        } catch (InboundConflictException) {
            return response()->json(['message' => 'Webhook conflict'], 409);
        } catch (\JsonException|\RuntimeException $exception) {
            return response()->json(['message' => 'Webhook rejected'], 401);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Inbound webhook processing failed.', ['exception_class' => get_class($exception)]);
            return response()->json(['message' => 'Webhook rejected'], 400);
        }
    }

    private function rowOrFail(string $table, int $id, array $columns = ['*']): object
    {
        $row = DB::table($table)->select($columns)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }
}
