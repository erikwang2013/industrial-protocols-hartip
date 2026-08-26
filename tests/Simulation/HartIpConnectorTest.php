<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\IndustrialProtocols\HartIp\Tests\Simulation;

use Erikwang2013\IndustrialProtocols\Connection\ConnectionState;
use Erikwang2013\IndustrialProtocols\HartIp\Driver\HartIpDriver;
use Erikwang2013\IndustrialProtocols\HartIp\Exception\HartIpException;
use Erikwang2013\IndustrialProtocols\HartIp\Frame\HartIpFrame;
use Erikwang2013\IndustrialProtocols\HartIp\HartIpConnector;
use Erikwang2013\IndustrialProtocols\HartIp\HartIpProtocol;
use Erikwang2013\IndustrialProtocols\Profinet\Frame\ProfinetFrame;
use PHPUnit\Framework\TestCase;

class HartIpConnectorTest extends TestCase
{
    /**
     * Start a fake HART-IP gateway in a child process: reads the 9-byte header
     * plus payload, answers with a canned HART response payload. Serves
     * multiple requests on one connection until the client closes.
     */
    private function startGateway(int $port, int $status = 0x00): array
    {
        $proc = proc_open([PHP_BINARY, '-r', <<<'STUB'
            $server = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $errno, $errstr);
            if (!$server) { fwrite(STDERR, "server fail: $errstr\n"); exit(1); }
            echo "READY\n";
            flush();
            $client = @stream_socket_accept($server, 5);
            if ($client) {
                $payload = "\xFF\xFF\x86\x00\x01\x05\x00\x06"; // canned HART response
                while (($hdr = fread($client, 9)) !== '' && $hdr !== false) {
                    if (strlen($hdr) < 9) break;
                    $u = unpack('Cversion/Ctype/Cstatus/Creserved/nsequence/nmessageId/Clength', $hdr);
                    $body = fread($client, $u['length']);
                    $r = pack('CCCCnnC', 1, 1, (int) $argv[2], 0, $u['sequence'], $u['messageId'], strlen($payload)) . $payload;
                    fwrite($client, $r);
                }
                fclose($client);
            }
            fclose($server);
STUB, (string) $port, (string) $status], [1 => ['pipe', 'w']], $pipes);

        fgets($pipes[1]);
        return [$proc, $pipes];
    }

    public function testReadMultiplePointsAndMessageIdIncrement(): void
    {
        [$proc] = $this->startGateway(15070);

        $connector = new HartIpConnector(['host' => '127.0.0.1', 'port' => 15070, 'timeout' => 2000]);
        $connector->connect();
        $this->assertTrue($connector->isConnected());
        $this->assertSame(ConnectionState::HEALTHY, $connector->getHealth()->state);

        $result = $connector->read(['pv', 'loop_current']);
        $this->assertCount(2, $result);
        $this->assertSame(HartIpFrame::TYPE_RESPONSE, $result['pv']['message_type']);
        $this->assertSame(1, $result['pv']['message_id']);
        $this->assertSame(2, $result['loop_current']['message_id']);
        $this->assertSame('ffff860001050006', $result['pv']['hart_command']);

        $connector->disconnect();
        $this->assertFalse($connector->isConnected());
        $this->assertSame(ConnectionState::CLOSED, $connector->getHealth()->state);

        proc_close($proc);
    }

    public function testWriteAndCommandRoundTrip(): void
    {
        [$proc] = $this->startGateway(15071);

        $connector = new HartIpConnector(['host' => '127.0.0.1', 'port' => 15071, 'timeout' => 2000]);
        $connector->connect();

        $written = $connector->write('tag', ['TANK-2']);
        $this->assertSame(1, $written['tag']['message_id']);

        $frame = $connector->command(1);
        $this->assertInstanceOf(HartIpFrame::class, $frame);
        $this->assertSame(HartIpFrame::TYPE_RESPONSE, $frame->getMessageType());
        $this->assertSame(2, $frame->getMessageId());

        $connector->disconnect();
        proc_close($proc);
    }

    public function testErrorStatusResponseThrows(): void
    {
        [$proc] = $this->startGateway(15072, 0x40);

        $connector = new HartIpConnector(['host' => '127.0.0.1', 'port' => 15072, 'timeout' => 2000]);
        $connector->connect();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HART-IP error response: status 0x40');
        $connector->read('pv');

        $connector->disconnect();
        proc_close($proc);
    }

    public function testIncompleteResponseHeaderThrows(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', <<<'STUB'
            $server = stream_socket_server('tcp://127.0.0.1:15073', $errno, $errstr);
            if (!$server) { fwrite(STDERR, "server fail: $errstr\n"); exit(1); }
            echo "READY\n";
            flush();
            $client = @stream_socket_accept($server, 5);
            if ($client) {
                $req = fread($client, 9);
                fwrite($client, "\x01\x01\x00"); // truncated header, then close
                fclose($client);
            }
            fclose($server);
STUB, ], [1 => ['pipe', 'w']], $pipes);
        fgets($pipes[1]);

        $connector = new HartIpConnector(['host' => '127.0.0.1', 'port' => 15073, 'timeout' => 2000]);
        $connector->connect();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incomplete response header');
        $connector->read('pv');

        $connector->disconnect();
        proc_close($proc);
    }

    public function testConnectRefusedThrowsHartIpException(): void
    {
        $connector = new HartIpConnector(['host' => '127.0.0.1', 'port' => 15970, 'timeout' => 1000]);
        $this->expectException(HartIpException::class);
        $this->expectExceptionMessage('HART-IP connection failed');
        $connector->connect();
    }

    public function testHealthBeforeConnect(): void
    {
        $connector = new HartIpConnector([]);
        $this->assertSame(ConnectionState::CLOSED, $connector->getHealth()->state);
    }

    public function testDriverRejectsNonHartIpFrame(): void
    {
        $driver = new HartIpDriver('127.0.0.1', 15970, 1.0);
        $this->expectException(\InvalidArgumentException::class);
        $driver->send(ProfinetFrame::dcpIdentify());
    }

    public function testProtocolCreateConnector(): void
    {
        $protocol = new HartIpProtocol();
        $this->assertSame('hart-ip', $protocol->getName());
        $this->assertSame('1.1.1', $protocol->getVersion());
        $this->assertSame(5094, $protocol->getDefaultPort());
        $this->assertContains('tcp', $protocol->getSupportedVariants());
        $this->assertContains('udp', $protocol->getSupportedVariants());

        $connector = $protocol->createConnector(['host' => '127.0.0.1']);
        $this->assertInstanceOf(HartIpConnector::class, $connector);
    }
}
