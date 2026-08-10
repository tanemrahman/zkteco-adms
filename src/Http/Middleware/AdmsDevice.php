<?php

namespace TanemRahman\ZktecoAdms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TanemRahman\ZktecoAdms\Services\AdmsService;

class AdmsDevice
{
    public function __construct(private AdmsService $adms)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $serial = $request->query('SN', $request->query('sn'));

        if (empty($serial)) {
            $this->adms->logRequest([
                'endpoint' => $this->endpoint($request),
                'method' => $request->method(),
                'level' => 'error',
                'query' => $request->getQueryString(),
                'status_code' => 400,
                'message' => 'Missing SN',
                'ip' => $request->ip(),
            ]);

            return $this->text('Error: SN required', 400);
        }

        $device = $this->adms->resolveDevice((string) $serial, $request);

        if ($device === null) {
            $this->adms->logRequest([
                'serial' => (string) $serial,
                'endpoint' => $this->endpoint($request),
                'method' => $request->method(),
                'level' => 'warning',
                'status_code' => 200,
                'message' => 'Unknown device ignored (auto-register off)',
                'ip' => $request->ip(),
            ]);

            return $this->text($this->adms->ok(), 200);
        }

        if (!$this->adms->commKeyValid($device, $request)) {
            $this->adms->logRequest([
                'device_id' => $device->id,
                'serial' => $device->serial,
                'endpoint' => $this->endpoint($request),
                'method' => $request->method(),
                'level' => 'error',
                'status_code' => 401,
                'message' => 'Invalid comm key',
                'ip' => $request->ip(),
            ]);

            return $this->text('Error: invalid comm key', 401);
        }

        $this->adms->touch($device);
        $request->attributes->set('adms_device', $device);

        return $next($request);
    }

    private function endpoint(Request $request): string
    {
        return last(explode('/', $request->path()));
    }

    private function text(string $body, int $status): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
