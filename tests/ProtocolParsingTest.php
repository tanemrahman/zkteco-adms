<?php

namespace TanemRahman\ZktecoAdms\Tests;

use PHPUnit\Framework\TestCase;
use TanemRahman\ZktecoAdms\Services\AdmsService;
use TanemRahman\ZktecoAdms\Services\CommandService;

class ProtocolParsingTest extends TestCase
{
    public function test_parse_attlog_tab_separated(): void
    {
        $svc = new AdmsService();
        $records = $svc->parseAttlog("1001\t2026-08-10 09:15:00\t0\t1\t0\n1002\t2026-08-10 09:16:00\t1\t15");

        $this->assertCount(2, $records);
        $this->assertSame('1001', $records[0]['pin']);
        $this->assertSame('2026-08-10 09:15:00', $records[0]['timestamp']);
        $this->assertSame(0, $records[0]['status']);
        $this->assertSame(1, $records[0]['verify']);
    }

    public function test_parse_attlog_whitespace_fallback(): void
    {
        $svc = new AdmsService();
        $records = $svc->parseAttlog("55 2026-08-10 18:00:01 0 1");

        $this->assertCount(1, $records);
        $this->assertSame('55', $records[0]['pin']);
        $this->assertSame('2026-08-10 18:00:01', $records[0]['timestamp']);
    }

    public function test_build_update_user_command(): void
    {
        $svc = new CommandService();
        $cmd = $svc->buildUpdateUser([
            'pin' => 1001,
            'name' => 'Karim',
            'privilege' => 0,
            'card' => 'ABC',
        ]);

        $this->assertStringStartsWith('DATA UPDATE USERINFO ', $cmd);
        $this->assertStringContainsString('PIN=1001', $cmd);
        $this->assertStringContainsString('Name=Karim', $cmd);
        $this->assertStringContainsString('Card=ABC', $cmd);
    }

    public function test_encode_device_time(): void
    {
        $svc = new CommandService();
        $encoded = $svc->encodeDeviceTime(new \DateTimeImmutable('2026-08-10 12:30:45'));

        $this->assertIsInt($encoded);
        $this->assertGreaterThan(0, $encoded);
        $this->assertSame(
            'SET OPTIONS DateTime=' . $encoded,
            $svc->buildSetTime(new \DateTimeImmutable('2026-08-10 12:30:45'))
        );
    }

    public function test_parse_operlog_user_line(): void
    {
        $svc = new AdmsService();
        $records = $svc->parseOperlog("USER PIN=1\tName=Alice\tPri=0\tPasswd=\tCard=123");

        $this->assertCount(1, $records);
        $this->assertSame('USER', $records[0]['tag']);
        $this->assertSame('1', $records[0]['fields']['PIN']);
        $this->assertSame('Alice', $records[0]['fields']['Name']);
    }
}
