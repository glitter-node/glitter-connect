<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CredentialService
{
    public function put(int $connectionId, string $key, string $secret): void
    {
        DB::table('glitter_connect_credentials')->updateOrInsert(
            ['connection_id' => $connectionId, 'key' => $key],
            ['secret_encrypted' => Crypt::encryptString($secret), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function get(int $connectionId, string $key): ?string
    {
        $encrypted = DB::table('glitter_connect_credentials')->where('connection_id', $connectionId)->where('key', $key)->value('secret_encrypted');

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    public function deleteForConnection(int $connectionId): void
    {
        DB::table('glitter_connect_credentials')->where('connection_id', $connectionId)->delete();
    }
}
