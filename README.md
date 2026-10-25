# Glitter Connect

Gnuboard7 모듈 `glitter-connect`는 Connection과 Endpoint를 관리하고, 서명된 Inbound Webhook을 이벤트로 저장한 뒤 Subscription에 따라 HTTPS 목적지로 전달합니다.

## 요구사항 및 구성

- PHP 8.2 이상 및 Gnuboard7 7.1.3 이상
- 모듈 ID: `glitter-connect`
- 테이블: 프로젝트 DB 접두사가 붙는 `glitter_connect_*` 테이블
- 기본 보존 기간: 90일
- 기본 Delivery 최대 시도 횟수: 12회
- Worker 명령: `glitter-connect:deliver`; 모듈 스케줄은 매분 실행을 요청합니다.

모듈 설정에서 `delivery.retention_days`와 `delivery.max_attempts`를 조정할 수 있습니다. 유효 범위는 각각 1–3650일, 1–30회입니다.

## 설치 및 업데이트

`modules/_bundled/glitter-connect/`가 소스 기준입니다. 활성 모듈 디렉터리를 직접 수정하지 않습니다. 확장 명령은 현재 프로젝트의 공식 명령 규칙을 따릅니다.

동봉 프런트엔드 자산 생성:

```bash
/usr/local/bin/php83 artisan module:build glitter-connect --production
```

승인된 운영 변경 창에서 업데이트할 때의 예:

```bash
/usr/local/bin/php83 artisan module:update glitter-connect --source=bundled --force --vendor-mode=bundled --layout-strategy=overwrite --no-interaction
```

`module:update`는 활성 파일, 모듈 상태, 레이아웃, 권한·메뉴, 마이그레이션 및 게시 자산에 영향을 줄 수 있습니다. 이 저장소는 운영 checkout이므로 사전 승인 없이 실행하지 않습니다. `overwrite`는 활성 모듈에 있는 운영자 레이아웃 수정본도 덮어쓸 수 있습니다.

### 마이그레이션 순서

파일명 순서로 000001부터 000005까지 적용됩니다.

| Migration | 변경 | 기존 데이터 기본 동작 |
| --- | --- | --- |
| `000001_create_glitter_connect_tables` | Connection, Credential, Endpoint, Subscription, Event, Delivery, Attempt, Receipt, Replay 테이블과 FK/유니크 제약 생성 | 새 테이블 생성 |
| `000002_add_signature_version_to_glitter_connect_endpoints` | Endpoint `signature_version` 추가 | 기존 Endpoint는 `v0`; 신규 Endpoint API는 `v1` 지정 |
| `000003_add_body_sha256_to_glitter_connect_inbound_receipts` | Receipt 본문 SHA-256 추가(nullable) | 과거 Receipt는 `NULL`; v0는 연결된 Event가 있을 때 기존 receipt를 재사용하고 v1은 본문을 검증할 수 없어 충돌 처리 |
| `000004_add_signature_required_to_glitter_connect_connections` | Connection `signature_required` 추가 | 기존 Connection은 `false`; 신규 API 생성 기본값은 `true` |
| `000005_add_outbound_signature_versions` | Subscription 및 Delivery의 `outbound_signature_version` 추가 | 기존 행은 `v0`; 신규 Subscription API 기본값은 `v1`; 생성된 Delivery는 Subscription 버전을 snapshot |

추가 스키마 변경은 현재 필요하지 않습니다. 각 후속 migration은 테이블/컬럼 존재를 확인하고 추가 DDL을 수행하지만, 기본 migration의 기존 테이블 가드는 누락된 컬럼 전체를 자동 복구하지 않습니다. MariaDB DDL은 단계별로 커밋될 수 있으므로 migration 전체가 원자적으로 되돌려진다고 가정하지 않습니다.

### 백업 및 복구

운영 업데이트 전에 다음을 동일 시점 기준으로 확보하고 복원 가능성을 확인합니다.

1. Glitter Connect 관련 테이블과 DB의 일관된 백업
2. 동봉 모듈 소스 및 빌드 산출물의 버전 관리된 사본
3. 활성 모듈 파일과 운영자 수정 레이아웃의 백업
4. 현재 모듈 버전, 마이그레이션 적용 상태, 큐/스케줄러 상태 기록

실패 시 `000001`의 `down()`은 모듈 테이블과 데이터를 삭제하므로 운영 복구 수단으로 실행하지 않습니다. 부분 DDL 실패 후에는 실제 스키마와 migration 기록을 확인하고 백업을 보존한 채 호환되는 코드/수정 migration으로 복구합니다. DB 스냅샷 복원은 승인된 복구 절차에서만 수행합니다. 이 문서는 운영 DB를 조회하거나 복구를 실행하지 않았습니다.

## 관리자 기능 및 권한

관리자 화면 경로:

- `/admin/glitter-connect` — Overview
- `/admin/glitter-connect/connections`
- `/admin/glitter-connect/endpoints`
- `/admin/glitter-connect/subscriptions`
- `/admin/glitter-connect/events`
- `/admin/glitter-connect/deliveries`
- `/admin/glitter-connect/settings`

API는 `/api/modules/glitter-connect` 아래에 등록됩니다. 관리자 API는 `auth:sanctum`, `admin`, 모듈 권한을 적용합니다. 읽기는 `glitter-connect.connect.read`, 관리 변경은 `glitter-connect.connect.manage`, 재전달은 `glitter-connect.deliveries.replay`, 설정 변경은 `glitter-connect.settings.manage`입니다. 공개 Inbound 라우트는 Endpoint 서명 검증과 `throttle:30,1`을 적용합니다.

Connection UI는 생성, 이름/활성 상태 변경, 설정 확인, Secret 교체를 제공합니다. Endpoint UI는 생성, 활성 상태 변경, Endpoint key 및 발신 안내를 제공합니다. Subscription UI는 생성, 상태 변경, 대상 URL 및 서명 버전 변경을 제공합니다. 확인 버튼은 구성이 존재하는지만 확인하며 외부 연결을 시험하지 않습니다.

Connection 비활성화는 연결 Endpoint의 `status`를 바꾸지 않지만 Outbound와 연결 Endpoint의 Inbound를 막습니다. 재활성화하면 여전히 active인 Endpoint가 다시 수신합니다. Connection 삭제는 같은 트랜잭션에서 active Endpoint를 먼저 inactive 처리하고 Connection을 삭제합니다. Endpoint 행과 Receipt는 보존되며 FK의 `nullOnDelete`로 독립 Endpoint 상태가 됩니다. Credential과 Subscription은 Connection FK 정책에 따라 삭제됩니다. Subscription 삭제/Connection cascade 이후 기존 Delivery의 `subscription_id`는 nullable FK에 의해 null이 될 수 있으며 Event와 Delivery 보존·정리는 별도 정책을 따릅니다.

## Secret 및 서명 계약

Connection의 `api_secret`과 Endpoint의 `secret_encrypted`는 Laravel Crypt로 암호화해 저장합니다. Connection의 `signature_required=true`이면 신규 전송에서 Secret이 없을 때 전송하지 않고 Delivery를 dead-letter 처리합니다. 기존 Connection은 `false`로 유지되며, 이 정책에서는 Secret이 있으면 서명하고 없으면 unsigned 전달이 허용됩니다. 따라서 현재 데이터로 Secret이 원래 없었던 것과 이후 소실된 것을 구별할 수 없습니다.

Endpoint 생성 응답과 Connection Secret 교체 응답은 평문 Secret을 한 번 반환합니다. 관리자 화면은 응답값을 출력합니다. G7Core DevTools가 응답을 보존하는 환경에서는 Secret 기록 위험이 남습니다. 모듈 범위에서 검증된 코어 마스킹 확장 지점은 확인되지 않았으며, Secret 응답 계약은 변경하지 않았습니다. 운영자는 Secret이 노출된 기록을 민감정보로 취급하고, 유출이 의심되면 교체해야 합니다.

### Inbound 요청

```text
POST /api/modules/glitter-connect/inbound/{endpoint_key}
Content-Type: application/json
X-Glitter-Timestamp: <Unix seconds>
Idempotency-Key: <sender-generated key>
X-Glitter-Signature: <version-specific signature>
```

Timestamp는 서버 시각 기준 ±300초(경계 포함)여야 합니다. 원본 HTTP 본문은 최대 262,144바이트이며 Endpoint별 더 낮은 `max_payload_bytes`가 적용될 수 있습니다.

**v0** — 기존 Endpoint의 기본 계약:

```text
HMAC-SHA256(key=Endpoint Secret, message=timestamp + "." + raw body)
X-Glitter-Signature: sha256=<lowercase 64-hex>
```

기존 v0 검증기는 `sha256=` 접두사 없는 hex digest도 허용합니다. Idempotency-Key는 비어 있지 않은 최대 160바이트 값이어야 합니다. `event_id`가 생략되면 서버가 UUID를 생성합니다. v0 서명은 Idempotency-Key를 서명 입력에 포함하지 않으므로 신규 발신자는 v1을 사용해야 합니다.

**v1** — 신규 Endpoint 기본 계약:

```text
message bytes = "glitter-connect-v1\n" + timestamp + "\n" + Idempotency-Key + "\n" + raw HTTP body
X-Glitter-Signature: v1=<lowercase 64-hex HMAC-SHA256>
```

Idempotency-Key는 `[A-Za-z0-9._~-]` 문자만 사용하는 1–160 ASCII 바이트여야 합니다. JSON을 파싱하거나 재직렬화하지 말고 수신한 raw body 바이트 그대로 서명합니다. JSON의 `event_id`는 공백만 있는 값이 아닌 문자열 1–100자이며 Unicode Cc 제어 문자를 포함할 수 없습니다. 동일 이벤트 재전송은 같은 `event_id`, Idempotency-Key, 본문을 재사용해야 합니다. Endpoint와 키와 본문이 같으면 기존 Receipt를 재사용하고, 키는 같고 본문이 다르면 409로 거부합니다. 다른 키로 같은 논리 Event가 들어와도 `(source,event_id)` 유니크 제약으로 Event/Delivery 생성을 중복하지 않습니다.

버전은 Endpoint에 고정됩니다. v1에서 v0으로 서명 fallback하지 않습니다. 양쪽 모듈 간 수신에는 발신 Connection Secret과 대상 Endpoint Secret을 동일하게 설정하고 같은 서명 버전을 선택해야 합니다.

### Outbound Delivery

Subscription별 서명 버전은 신규 생성 시 v1, 기존 Subscription은 v0입니다. 명시적으로 v0 선택이 가능합니다. Delivery 생성 시 Subscription 버전을 snapshot하고 Retry/Replay는 해당 Delivery의 값을 유지합니다. Subscription 버전 변경은 이미 생성된 Delivery를 변경하지 않습니다.

Outbound v0은 `timestamp + "." + raw JSON body`를 HMAC-SHA256으로 서명하고 `X-Glitter-Signature: sha256=<hex>`를 보냅니다. v1은 Inbound v1과 같은 입력 바이트 형식을 사용하고 `v1=<hex>`를 보냅니다. 두 버전 모두 `X-Glitter-Timestamp`, `Idempotency-Key`, `X-Glitter-Event` 헤더를 보냅니다. 실제 전송 본문과 v1 HMAC의 body는 동일한 직렬화 문자열입니다.

Outbound payload에는 `spec_version`, `event_id`, `event_type`, `source`, `subject`, `occurred_at`, `received_at`, `correlation_id`, `schema_version`, `data`, `metadata`가 포함됩니다. v1 전송은 `event_id`가 Inbound v1 허용 규칙에 맞는지 먼저 검증합니다. 실패는 재시도하지 않는 데이터 오류로 dead-letter 처리됩니다. v0에는 이 검증을 적용하지 않습니다.

Subscription 목적지는 HTTPS만 허용합니다. URL credentials, localhost/`.local`, 사설/예약 IP 및 비표준 hostname 형식을 거부하고 DNS 응답 전체가 public 주소인지 확인합니다. Outbound는 public IPv4를 `CURLOPT_RESOLVE`로 pin하고 redirect를 끄며, 요청 시간 제한은 10초, 응답 본문 제한은 262,144바이트입니다. IDN은 ASCII punycode hostname으로 입력해야 합니다. PHP DNS 조회 자체의 별도 timeout은 설정되어 있지 않습니다.

HTTP 비성공/네트워크 오류는 재시도 정책을 따릅니다. 기본 최대 시도는 12회이며 지수 지연은 최대 21,600초로 제한됩니다. HTTP 3xx와 일반 4xx(408, 425, 429 제외)는 재시도하지 않고, 429의 유효한 `Retry-After`는 최대 86,400초로 제한됩니다. v1 event_id 검증 실패는 영구 데이터 오류이므로 자동 재시도와 수동 Replay를 모두 거부합니다. 그 밖의 수동 Replay는 dead-letter, retry-scheduled, delivered 상태를 다시 pending으로 바꾸며 같은 Delivery ID, 서명 버전, Idempotency-Key를 유지합니다. 수신자가 같은 Idempotency-Key를 중복 제거하면 Replay가 새로운 비멱등 부수효과를 만들지 않을 수 있으므로 대상 시스템의 계약을 확인하세요.

Worker는 `processing` 상태에 2분 lease를 두고 만료된 작업을 재시도 예약 상태로 되돌립니다. Event 보존 정리는 pending, processing, retry-scheduled Delivery가 있는 Event를 삭제하지 않습니다. 정리 가능한 Event가 삭제되면 FK cascade로 해당 terminal Delivery 및 Attempts가 삭제될 수 있고 Receipt는 보존되지만 Event 참조는 null이 됩니다.

## 호환성 및 미해결 운영 위험

- 기존 Endpoint·Subscription·Delivery는 v0로 유지되어 기존 서명 수신자와 호환됩니다. 새 Endpoint·Subscription은 v1을 사용합니다.
- v0는 서명에서 Idempotency-Key를 보호하지 않고 `event_id` 누락 시 중복 이벤트 방지 보장이 약합니다. 수신자를 준비한 뒤 Endpoint/Subscription 단위로 v1 전환하세요.
- 과거 Receipt의 `body_sha256=NULL` 값은 본문 동일성을 증명할 수 없습니다. v0 호환 경로만 제한적으로 재사용합니다.
- optional 서명 Connection에서 Secret 소실을 원래 미설정과 식별할 수 없습니다. 그 Connection은 unsigned 전달될 수 있습니다.
- DevTools의 Secret 요청/응답 기록 위험은 코어 소유 기능이며 이 모듈은 우회하지 않습니다.
- Connection/Subscription 상태가 HTTP 요청 시작 직후 바뀌면 이미 시작한 외부 요청을 취소할 수 없습니다.
- MariaDB/InnoDB 잠금·부분 DDL·동시성 동작은 운영 DB에서 검증하지 않았습니다. 변경 전 스냅샷과 비운영 검증 창을 준비하세요.

## 개발 검증

PHP 정적 문법 확인은 `/usr/local/bin/php83 -l <file>`을 사용합니다. 모듈 단위 PHPUnit은 프로젝트 `tests/bootstrap.php`가 현재 공유 런타임에서 실행을 차단하므로 이 환경에서 우회 실행하지 않습니다. Feature 테스트는 `DatabaseTransactions`로 프로젝트 설정 DB에 연결되는 테스트이므로 격리 DB/환경을 만들거나 실행하지 않습니다. 운영 데이터, API, 실제 Webhook, 외부 네트워크 검증은 별도 승인이 필요합니다.
