<?php

namespace TanemRahman\ZktecoAdms\Services;

use Illuminate\Http\Request;
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;
use TanemRahman\ZktecoAdms\Models\ZktecoDeviceUser;

/**
 * OPERLOG / USERINFO / template sync from device → DB,
 * plus helpers to push user/biometric changes device-ward.
 */
class UserSyncService
{
    public function __construct(
        private AdmsService $adms,
        private CommandService $commands,
    ) {
    }

    /**
     * @return array{users:int,templates:int,operations:int,count:int,stamp_advanced:bool,response:string}
     */
    public function receiveOperlog(ZktecoDevice $device, Request $request, string $table = 'OPERLOG'): array
    {
        $body = $request->getContent();
        $records = $this->adms->parseOperlog($body);

        $users = 0;
        $templates = 0;
        $operations = 0;

        foreach ($records as $record) {
            switch ($record['tag']) {
                case 'USER':
                case 'USERINFO':
                    $this->adms->upsertDeviceUser($device, $record['fields']);
                    $users++;
                    break;

                case 'FP':
                case 'FINGERPRINT':
                case 'FINGERTMP':
                case 'FACE':
                case 'BIOPHOTO':
                case 'BIODATA':
                    $this->adms->markTemplate($device, $record['tag'], $record['fields']);
                    $templates++;
                    break;

                default:
                    $operations++;
                    break;
            }
        }

        $device->forceFill([
            'user_count' => ZktecoDeviceUser::where('serial', $device->serial)->count(),
        ])->saveQuietly();

        $emptyBody = trim($body) === '';
        $count = $users + $templates + $operations;
        $stampAdvanced = false;

        if ($emptyBody || $count > 0) {
            $this->adms->updateStamp($device, 'OPERLOG', $request->query('Stamp'));
            $stampAdvanced = true;
        }

        return [
            'users' => $users,
            'templates' => $templates,
            'operations' => $operations,
            'count' => $count,
            'stamp_advanced' => $stampAdvanced,
            'response' => $this->adms->dataOk($count),
            'body' => $body,
        ];
    }

    /**
     * @param  array{pin:string|int, name?:string, privilege?:int, password?:string, card?:string, group?:string|int, timezone?:string}  $user
     */
    public function pushUser(ZktecoDevice $device, array $user)
    {
        return $this->commands->addUser($device, $user);
    }

    public function deleteUser(ZktecoDevice $device, string|int $pin)
    {
        return $this->commands->deleteUser($device, $pin);
    }
}
