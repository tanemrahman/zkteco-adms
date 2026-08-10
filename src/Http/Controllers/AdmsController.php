<?php

namespace TanemRahman\ZktecoAdms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use TanemRahman\ZktecoAdms\Http\Requests\AdmsDataRequest;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Services\AdmsService;
use TanemRahman\ZktecoAdms\Services\DeviceIdentityService;
use TanemRahman\ZktecoAdms\Services\UserSyncService;

class AdmsController extends Controller
{
    public function __construct(
        private AdmsService $adms,
        private DeviceIdentityService $identity,
        private UserSyncService $userSync,
    ) {
    }

    public function handshake(Request $request): Response
    {
        /** @var ZktecoDevice $device */
        $device = $request->attributes->get('adms_device');

        $this->identity->captureHandshake($device, $request);
        $body = $this->adms->buildInitOptions($device);

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'GET',
            'query' => $request->getQueryString(),
            'response' => $body,
            'message' => 'handshake / option block',
            'ip' => $request->ip(),
        ]);

        return $this->text($body);
    }

    public function receiveData(AdmsDataRequest $request): Response
    {
        /** @var ZktecoDevice $device */
        $device = $request->attributes->get('adms_device');
        $table = $request->tableName();

        return match ($table) {
            'ATTLOG' => $this->storeAttlog($request, $device),
            'ATTPHOTO' => $this->storeAttphoto($request, $device),
            'OPERLOG', 'USERINFO', 'USER', 'FINGERTMP', 'FACE',
            'USERPIC', 'BIODATA', 'BIOPHOTO', 'WORKCODE'
                => $this->syncOperlog($request, $device, $table),
            'OPTIONS' => $this->receiveOptions($request, $device),
            default => $this->acknowledgeUnknown($request, $device, $table),
        };
    }

    private function storeAttphoto(AdmsDataRequest $request, ZktecoDevice $device): Response
    {
        $body = $request->getContent();
        $stamp = $request->stamp();
        $result = $this->adms->storeAttphoto($device, $body, $stamp);

        // Advance stamp only when the photo was persisted (or feature disabled → still ack
        // so the device does not retry forever when intentionally off).
        $advance = $result['saved'] || ($result['reason'] === 'disabled');
        if ($advance) {
            $this->adms->updateStamp($device, 'ATTPHOTO', $stamp);
        }

        $response = $this->adms->ok();

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'POST',
            'table_name' => 'ATTPHOTO',
            'query' => $request->getQueryString(),
            'message' => sprintf(
                'attphoto saved=%s bytes=%d reason=%s stamp=%s',
                $result['saved'] ? 'yes' : 'no',
                $result['bytes'],
                $result['reason'] ?? 'ok',
                $advance ? 'advanced' : 'held'
            ),
            'response' => $response,
            'records_count' => $result['saved'] ? 1 : 0,
            'level' => $result['saved'] || $result['reason'] === 'disabled' ? 'info' : 'warning',
            'ip' => $request->ip(),
        ]);

        return $this->text($response);
    }

    private function storeAttlog(AdmsDataRequest $request, ZktecoDevice $device): Response
    {
        $records = $this->adms->parseAttlog($request->getContent());
        $result = $this->adms->storeAttlog($device, $records);

        // Only advance stamp when no rows were hard-rejected (parse/retention/skew).
        // Duplicates are fine — device may safely clear them.
        if (($result['rejected'] ?? 0) === 0) {
            $this->adms->updateStamp($device, 'ATTLOG', $request->stamp());
        }

        $response = $this->adms->dataOk($result['saved']);

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'POST',
            'table_name' => 'ATTLOG',
            'query' => $request->getQueryString(),
            'body' => $request->getContent(),
            'response' => $response,
            'records_count' => $result['saved'],
            'level' => ($result['rejected'] ?? 0) > 0 ? 'warning' : 'info',
            'message' => sprintf(
                'attlog saved=%d dup=%d rej=%d stamp=%s',
                $result['saved'],
                $result['duplicates'],
                $result['rejected'],
                ($result['rejected'] ?? 0) === 0 ? 'advanced' : 'held'
            ),
            'ip' => $request->ip(),
        ]);

        return $this->text($response);
    }

    private function syncOperlog(AdmsDataRequest $request, ZktecoDevice $device, string $table): Response
    {
        $result = $this->userSync->receiveOperlog($device, $request, $table);

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'POST',
            'table_name' => $table,
            'query' => $request->getQueryString(),
            'body' => $result['body'],
            'response' => $result['response'],
            'records_count' => $result['count'],
            'level' => ($result['count'] > 0 || trim($result['body']) === '') ? 'info' : 'warning',
            'message' => sprintf(
                'users=%d templates=%d ops=%d stamp=%s',
                $result['users'],
                $result['templates'],
                $result['operations'],
                $result['stamp_advanced'] ? 'advanced' : 'held'
            ),
            'ip' => $request->ip(),
        ]);

        return $this->text($result['response']);
    }

    private function receiveOptions(Request $request, ZktecoDevice $device): Response
    {
        $body = $request->getContent();
        $this->identity->captureOptions($device, $body);

        $response = $this->adms->ok();
        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'POST',
            'table_name' => 'OPTIONS',
            'query' => $request->getQueryString(),
            'body' => $body,
            'response' => $response,
            'message' => 'push options accepted',
            'ip' => $request->ip(),
        ]);

        return $this->text($response);
    }

    public function ping(Request $request): Response
    {
        return $this->text($this->adms->ok());
    }

    public function registry(Request $request): Response
    {
        /** @var ZktecoDevice|null $device */
        $device = $request->attributes->get('adms_device');

        $this->adms->logRequest([
            'device_id' => $device?->id,
            'serial' => $device?->serial,
            'endpoint' => 'registry',
            'method' => $request->method(),
            'query' => $request->getQueryString(),
            'body' => $request->getContent(),
            'response' => 'OK',
            'message' => 'registry probe (stub)',
            'ip' => $request->ip(),
        ]);

        return $this->text($this->adms->ok());
    }

    public function push(Request $request): Response
    {
        return $this->text($this->adms->ok());
    }

    public function fdata(Request $request): Response
    {
        /** @var ZktecoDevice|null $device */
        $device = $request->attributes->get('adms_device');

        $this->adms->logRequest([
            'device_id' => $device?->id,
            'serial' => $device?->serial,
            'endpoint' => 'fdata',
            'method' => 'POST',
            'table_name' => strtoupper((string) $request->query('table', 'TEMPLATE')),
            'query' => $request->getQueryString(),
            'message' => 'template/photo upload (' . strlen($request->getContent()) . ' bytes)',
            'ip' => $request->ip(),
        ]);

        return $this->text($this->adms->ok());
    }

    private function acknowledgeUnknown(AdmsDataRequest $request, ZktecoDevice $device, string $table): Response
    {
        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'POST',
            'table_name' => $table,
            'level' => 'warning',
            'query' => $request->getQueryString(),
            'body' => $request->getContent(),
            'response' => 'OK: 0',
            'message' => 'unhandled table: ' . $table,
            'ip' => $request->ip(),
        ]);

        return $this->text($this->adms->dataOk(0));
    }

    private function text(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
