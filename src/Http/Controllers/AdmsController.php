<?php

namespace TanemRahman\ZktecoAdms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use TanemRahman\ZktecoAdms\Http\Requests\AdmsDataRequest;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Services\AdmsService;

class AdmsController extends Controller
{
    public function __construct(private AdmsService $adms)
    {
    }

    public function handshake(Request $request): Response
    {
        /** @var ZktecoDevice $device */
        $device = $request->attributes->get('adms_device');

        $this->adms->captureHandshake($device, $request);
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
            'OPERLOG', 'USERINFO', 'USER', 'FINGERTMP', 'FACE',
            'USERPIC', 'BIODATA', 'BIOPHOTO', 'WORKCODE'
                => $this->syncOperlog($request, $device, $table),
            'OPTIONS' => $this->receiveOptions($request, $device),
            default => $this->acknowledgeUnknown($request, $device, $table),
        };
    }

    private function storeAttlog(AdmsDataRequest $request, ZktecoDevice $device): Response
    {
        $records = $this->adms->parseAttlog($request->getContent());
        $result = $this->adms->storeAttlog($device, $records);
        $this->adms->updateStamp($device, 'ATTLOG', $request->stamp());

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
            'message' => sprintf('attlog saved=%d dup=%d rej=%d', $result['saved'], $result['duplicates'], $result['rejected']),
            'ip' => $request->ip(),
        ]);

        return $this->text($response);
    }

    private function syncOperlog(AdmsDataRequest $request, ZktecoDevice $device, string $table): Response
    {
        $parsed = $this->adms->parseOperlog($request->getContent());
        $users = 0;

        foreach ($parsed as $row) {
            $tag = $row['tag'];
            if (in_array($tag, ['USER', 'USERINFO'], true)) {
                $this->adms->upsertDeviceUser($device, $row['fields']);
                $users++;
            } elseif (in_array($tag, ['FP', 'FINGERPRINT', 'FACE', 'BIOPHOTO', 'BIODATA_FP', 'BIODATA_FACE'], true)) {
                $this->adms->markTemplate($device, $tag, $row['fields']);
            }
        }

        $this->adms->updateStamp($device, $table === 'OPERLOG' ? 'OPERLOG' : $table, $request->stamp());
        $response = $this->adms->dataOk(count($parsed));

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'cdata',
            'method' => 'POST',
            'table_name' => $table,
            'query' => $request->getQueryString(),
            'body' => $request->getContent(),
            'response' => $response,
            'records_count' => count($parsed),
            'message' => "operlog users={$users}",
            'ip' => $request->ip(),
        ]);

        return $this->text($response);
    }

    private function receiveOptions(Request $request, ZktecoDevice $device): Response
    {
        $body = $request->getContent();
        // ~DeviceName=...,MAC=...
        if (preg_match('/DeviceName=([^\r\n,~]+)/i', $body, $m)) {
            $device->forceFill(['device_name' => trim($m[1])])->saveQuietly();
        }

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
