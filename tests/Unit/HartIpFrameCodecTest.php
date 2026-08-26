<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\IndustrialProtocols\HartIp\Tests\Unit;

use Erikwang2013\IndustrialProtocols\HartIp\Exception\HartIpException;
use Erikwang2013\IndustrialProtocols\HartIp\Frame\HartIpFrame;
use PHPUnit\Framework\TestCase;

class HartIpFrameCodecTest extends TestCase
{
    public function testSequenceNumberMasking(): void
    {
        $frame = new HartIpFrame(HartIpFrame::TYPE_REQUEST, 65536 + 7, 0);
        $this->assertSame(7, $frame->getSequenceNumber());
    }

    public function testMessageIdMasking(): void
    {
        $frame = new HartIpFrame(HartIpFrame::TYPE_REQUEST, 0, 131073);
        $this->assertSame(1, $frame->getMessageId());
    }

    public function testMaxIdValuesPreserved(): void
    {
        $frame = new HartIpFrame(HartIpFrame::TYPE_REQUEST, 0xFFFF, 0xFFFF);
        $bytes = $frame->toBytes();
        $this->assertSame(0xFF, ord($bytes[4]));
        $this->assertSame(0xFF, ord($bytes[5]));
        $this->assertSame(0xFF, ord($bytes[6]));
        $this->assertSame(0xFF, ord($bytes[7]));
    }

    public function testPublishFrameIsNotError(): void
    {
        $frame = new HartIpFrame(HartIpFrame::TYPE_PUBLISH, 1, 1);
        $this->assertFalse($frame->isError());
        $this->assertSame(HartIpFrame::TYPE_PUBLISH, ord($frame->toBytes()[1]));
    }

    public function testErrorTypeIsError(): void
    {
        $frame = new HartIpFrame(HartIpFrame::TYPE_ERROR, 1, 1);
        $this->assertTrue($frame->isError());
    }

    public function testNonZeroStatusIsError(): void
    {
        $frame = new HartIpFrame(HartIpFrame::TYPE_RESPONSE, 1, 1, '', HartIpFrame::STATUS_DEVICE_ERROR);
        $this->assertTrue($frame->isError());
        $this->assertSame(HartIpFrame::STATUS_DEVICE_ERROR, $frame->getStatus());
    }

    public function testRoundTripWithEmptyPayload(): void
    {
        $original = HartIpFrame::request(1, 2, '');
        $bytes = $original->toBytes();
        $this->assertSame(9, strlen($bytes));
        $this->assertSame(0, ord($bytes[8])); // payload length 0

        $parsed = HartIpFrame::fromBytes($bytes);
        $this->assertSame('', $parsed->getHartCommand());
        $this->assertFalse($parsed->isError());
    }

    public function testRoundTripWithLargePayload(): void
    {
        $payload = str_repeat("\xAB", 200);
        $original = HartIpFrame::response(9, 8, $payload, HartIpFrame::STATUS_OK);
        $parsed = HartIpFrame::fromBytes($original->toBytes());

        $this->assertSame($payload, $parsed->getHartCommand());
        $this->assertSame(9, $parsed->getSequenceNumber());
        $this->assertSame(8, $parsed->getMessageId());
        $this->assertSame(HartIpFrame::TYPE_RESPONSE, $parsed->getMessageType());
        $this->assertSame(1, $parsed->getVersion());
    }

    public function testFromBytesTruncatedPayloadKeepsAvailableBytes(): void
    {
        // Header declares 5 payload bytes but only 2 follow: parse keeps the 2.
        $bytes = pack('CCCCnnC', 1, 1, 0, 0, 1, 1, 5) . "\x01\x02";
        $parsed = HartIpFrame::fromBytes($bytes);
        $this->assertSame("\x01\x02", $parsed->getHartCommand());
    }

    public function testInvalidVersionThrows(): void
    {
        $bytes = pack('CCCCnnC', 2, 0, 0, 0, 1, 1, 0) . 'x';
        $this->expectException(HartIpException::class);
        $this->expectExceptionMessage('Invalid HART-IP protocol version: 2');
        HartIpFrame::fromBytes($bytes);
    }

    public function testHeaderTooShortThrows(): void
    {
        $this->expectException(HartIpException::class);
        $this->expectExceptionMessage('HART-IP header too short: 4 bytes');
        HartIpFrame::fromBytes("\x01\x01\x00\x00");
    }

    public function testGetDataStructure(): void
    {
        $frame = HartIpFrame::request(3, 4, "\x01");
        $data = $frame->getData();
        $this->assertSame(1, $data['version']);
        $this->assertSame(HartIpFrame::TYPE_REQUEST, $data['message_type']);
        $this->assertSame(0, $data['status']);
        $this->assertSame(3, $data['sequence_number']);
        $this->assertSame(4, $data['message_id']);
        $this->assertSame('01', $data['hart_command']);
    }

    public function testResponseFactory(): void
    {
        $frame = HartIpFrame::response(5, 6, "\x02", HartIpFrame::STATUS_COMMAND_ERROR);
        $this->assertSame(HartIpFrame::TYPE_RESPONSE, $frame->getMessageType());
        $this->assertSame(HartIpFrame::STATUS_COMMAND_ERROR, $frame->getStatus());
        $this->assertTrue($frame->isError());
    }

    public function testRequestFactory(): void
    {
        $frame = HartIpFrame::request(5, 6, "\x02");
        $this->assertSame(HartIpFrame::TYPE_REQUEST, $frame->getMessageType());
        $this->assertFalse($frame->isError());
    }
}
