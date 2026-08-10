<?php

namespace TanemRahman\ZktecoAdms\Exceptions;

use RuntimeException;

class DeviceNotFoundException extends RuntimeException
{
    public static function forSerial(string $serial): self
    {
        return new self("ADMS device not found for serial [{$serial}].");
    }
}
