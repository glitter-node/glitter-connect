<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Facades\DB;

class ConnectionService
{
    public function list(int $perPage = 25): mixed
    {
        return DB::table('glitter_connect_connections')->select(['id', 'name', 'provider', 'status', 'signature_required', 'created_at', 'updated_at'])->orderByDesc('id')->paginate(min(100, max(1, $perPage)));
    }

    public function create(array $data): object
    {
        $signatureRequired = (bool) ($data['signature_required'] ?? true);
        $id = DB::table('glitter_connect_connections')->insertGetId(['name' => $data['name'], 'provider' => $data['provider'] ?? 'generic', 'status' => 'active', 'signature_required' => $signatureRequired, 'config' => json_encode($data['config'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
        if (! empty($data['secret'])) app(CredentialService::class)->put($id, 'api_secret', $data['secret']);

        return DB::table('glitter_connect_connections')->select(['id', 'name', 'provider', 'status', 'signature_required', 'created_at', 'updated_at'])->find($id);
    }

    public function update(int $id, array $data): object
    {
        DB::table('glitter_connect_connections')->where('id', $id)->update(array_filter(['name' => $data['name'] ?? null, 'status' => $data['status'] ?? null, 'config' => isset($data['config']) ? json_encode($data['config']) : null, 'updated_at' => now()], static fn ($value) => $value !== null));

        $row = DB::table('glitter_connect_connections')->select(['id', 'name', 'provider', 'status', 'signature_required', 'created_at', 'updated_at'])->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id): void {
            DB::table('glitter_connect_connections')->where('id', $id)->lockForUpdate()->first(['id']);
            DB::table('glitter_connect_endpoints')
                ->where('connection_id', $id)
                ->where('status', 'active')
                ->update(['status' => 'inactive', 'updated_at' => now()]);

            app(CredentialService::class)->deleteForConnection($id);
            DB::table('glitter_connect_connections')->where('id', $id)->delete();
        });
    }
}
