<?php

namespace Modules\Glitter\Connect;

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => 'Glitter Connect', 'en' => 'Glitter Connect'],
            'description' => ['ko' => '이벤트 연동 권한', 'en' => 'Event integration permissions'],
            'categories' => [
                ['identifier' => 'connect', 'name' => ['ko' => '연동 관리', 'en' => 'Integration management'], 'description' => ['ko' => '연동 데이터 조회 및 관리', 'en' => 'Read and manage integrations'], 'permissions' => [
                    ['action' => 'read', 'name' => ['ko' => '조회', 'en' => 'Read'], 'description' => ['ko' => '연동 데이터를 조회합니다.', 'en' => 'Read integration data.'], 'type' => 'admin', 'roles' => ['admin']],
                    ['action' => 'manage', 'name' => ['ko' => '관리', 'en' => 'Manage'], 'description' => ['ko' => '연결, Endpoint, 구독을 관리합니다.', 'en' => 'Manage connections, endpoints, and subscriptions.'], 'type' => 'admin', 'roles' => ['admin']],
                ]],
                ['identifier' => 'deliveries', 'name' => ['ko' => '전달', 'en' => 'Deliveries'], 'description' => ['ko' => '이벤트 전달 작업', 'en' => 'Event delivery operations'], 'type' => 'admin', 'permissions' => [
                    ['action' => 'replay', 'name' => ['ko' => '재전달', 'en' => 'Replay'], 'description' => ['ko' => '실패하거나 완료된 전달을 다시 실행합니다.', 'en' => 'Replay failed or completed deliveries.'], 'type' => 'admin', 'roles' => ['admin']],
                ]],
                ['identifier' => 'settings', 'name' => ['ko' => '설정', 'en' => 'Settings'], 'description' => ['ko' => '모듈 동작 설정', 'en' => 'Module settings'], 'type' => 'admin', 'permissions' => [
                    ['action' => 'manage', 'name' => ['ko' => '설정 관리', 'en' => 'Manage settings'], 'description' => ['ko' => '전달 및 보존 설정을 변경합니다.', 'en' => 'Change delivery and retention settings.'], 'type' => 'admin', 'roles' => ['admin']],
                ]],
            ],
        ];
    }

    public function getAdminMenus(): array
    {
        return [[
            'name' => ['ko' => 'Glitter Connect', 'en' => 'Glitter Connect'],
            'slug' => 'glitter-connect',
            'url' => null,
            'icon' => 'fas fa-plug',
            'order' => 70,
            'children' => [[
                'name' => ['ko' => 'Overview', 'en' => 'Overview'],
                'slug' => 'glitter-connect-overview',
                'url' => '/admin/glitter-connect',
                'icon' => 'fas fa-chart-line',
                'order' => 1,
                'permission' => 'glitter-connect.connect.read',
            ]],
        ]];
    }

    public function getSettingsSchema(): array
    {
        return [
            'delivery' => [
                'retention_days' => ['type' => 'integer', 'min' => 1, 'max' => 3650],
                'max_attempts' => ['type' => 'integer', 'min' => 1, 'max' => 30],
            ],
        ];
    }

    public function getConfigValues(): array
    {
        return ['delivery' => ['retention_days' => 90, 'max_attempts' => 12]];
    }

    public function getSchedules(): array
    {
        return [[
            'command' => 'glitter-connect:deliver',
            'schedule' => 'everyMinute',
            'description' => 'Process Glitter Connect deliveries',
        ]];
    }

    public function getMetadata(): array
    {
        return ['author' => 'Glitter.kr', 'brand' => 'Glitter.kr', 'homepage' => 'https://glitter.kr'];
    }
}
