<?php

namespace TanemRahman\ZktecoAdms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoHeartbeatLog;
use TanemRahman\ZktecoAdms\Services\AdmsService;
use TanemRahman\ZktecoAdms\Services\CommandService;
use TanemRahman\ZktecoAdms\Services\DeviceIdentityService;

class CommandController extends Controller
{
    public function __construct(
        private AdmsService $adms,
        private CommandService $commands,
        private DeviceIdentityService $identity,
    ) {
    }

    public function poll(Request $request): Response
    {
        /** @var ZktecoDevice $device */
        $device = $request->attributes->get('adms_device');

        if ($info = $request->query('INFO')) {
            $this->identity->syncInfo($device, (string) $info);
        }

        $pending = $this->commands->pending($device);
        $body = $pending->isNotEmpty()
            ? $this->commands->dispatchToDevice($pending)
            : $this->adms->ok();

        ZktecoHeartbeatLog::create([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'ip' => $request->ip(),
            'info' => $request->query('INFO') ?: $request->userAgent(),
            'commands_sent' => $pending->count(),
            'created_at' => now(),
        ]);

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'getrequest',
            'method' => 'GET',
            'query' => $request->getQueryString(),
            'response' => $body,
            'records_count' => $pending->count(),
            'message' => $pending->count() . ' command(s) dispatched',
            'ip' => $request->ip(),
        ]);

        return response($body, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function reply(Request $request): Response
    {
        /** @var ZktecoDevice $device */
        $device = $request->attributes->get('adms_device');
        $body = $request->getContent();
        $handled = 0;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            parse_str($line, $reply);
            $normalized = [];
            foreach ($reply as $k => $v) {
                $normalized[ucfirst(strtolower($k)) === 'Id' ? 'ID' : ucfirst(strtolower($k))] = $v;
            }

            // INFO command replies often include firmware counters in CMD / body.
            if (!empty($normalized['CMD']) && strtoupper((string) $normalized['CMD']) === 'INFO') {
                $this->identity->syncInfo($device, (string) ($normalized['Return'] ?? $line));
            }

            if ($this->commands->recordReply($normalized)) {
                $handled++;
            }
        }

        $response = $this->adms->ok();

        $this->adms->logRequest([
            'device_id' => $device->id,
            'serial' => $device->serial,
            'endpoint' => 'devicecmd',
            'method' => 'POST',
            'query' => $request->getQueryString(),
            'body' => $body,
            'response' => $response,
            'records_count' => $handled,
            'message' => $handled . ' command result(s) recorded',
            'ip' => $request->ip(),
        ]);

        return response($response, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
