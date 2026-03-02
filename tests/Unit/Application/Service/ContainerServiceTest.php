<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Tests\Unit\Application\Service;

use Wow\Ext\Containers\Application\Service\ContainerService;
use Wow\Ext\Containers\Contracts\Dto\ContainerDto;
use Wow\Ext\Containers\Contracts\Dto\ContainerStatsDto;
use Wow\Ext\Containers\Infrastructure\Adapter\DockerCliAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ContainerServiceTest extends TestCase
{
    private ContainerService $service;
    private DockerCliAdapter&MockObject $dockerCli;
    private MessageBusInterface&MockObject $eventBus;

    protected function setUp(): void
    {
        $this->dockerCli = $this->createMock(DockerCliAdapter::class);
        $this->eventBus = $this->createMock(MessageBusInterface::class);
        $this->eventBus->method('dispatch')->willReturnCallback(
            fn (object $message) => new Envelope($message),
        );

        $this->service = new ContainerService(
            $this->dockerCli,
            $this->eventBus,
            new NullLogger(),
        );
    }

    public function testListReturnsContainerDtos(): void
    {
        $this->dockerCli->method('listContainers')->willReturn([
            [
                'ID' => 'abc123def456',
                'Names' => 'my-app',
                'Image' => 'nginx:latest',
                'State' => 'running',
                'Status' => 'Up 2 hours',
                'Ports' => [],
                'Networks' => [],
                'CreatedAt' => '2026-03-01',
                'Labels' => [],
                'isSfPanel' => false,
            ],
        ]);

        $result = $this->service->list();

        self::assertCount(1, $result);
        self::assertInstanceOf(ContainerDto::class, $result[0]);
        self::assertSame('abc123def456', $result[0]->id);
        self::assertSame('my-app', $result[0]->name);
        self::assertSame('green', $result[0]->statusColor);
        self::assertFalse($result[0]->isSfPanel);
    }

    public function testListDetectsSfPanelContainers(): void
    {
        $this->dockerCli->method('listContainers')->willReturn([
            [
                'ID' => 'abc123def456',
                'Names' => 'sfpanel',
                'Image' => 'sfpanel:latest',
                'State' => 'running',
                'Status' => 'Up 2 hours',
                'Ports' => [],
                'Networks' => [],
                'CreatedAt' => '2026-03-01',
                'Labels' => [],
                'isSfPanel' => true,
            ],
        ]);

        $result = $this->service->list();

        self::assertTrue($result[0]->isSfPanel);
    }

    public function testStartBlocksSfPanelContainer(): void
    {
        $this->dockerCli->method('inspectContainer')->willReturn([
            'Id' => 'abc123def456',
            'Name' => '/sfpanel',
            'Config' => ['Labels' => ['com.docker.compose.project' => 'sfpanel']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot start sfPanel system container');

        $this->service->start('abc123def456');
    }

    public function testStopBlocksSfPanelContainer(): void
    {
        $this->dockerCli->method('inspectContainer')->willReturn([
            'Id' => 'abc123def456',
            'Name' => '/sfpanel',
            'Config' => ['Labels' => ['com.docker.compose.project' => 'sfpanel']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot stop sfPanel system container');

        $this->service->stop('abc123def456');
    }

    public function testGetLogsReturnsString(): void
    {
        $this->dockerCli->method('getLogs')->willReturn("line1\nline2");

        $result = $this->service->getLogs('abc123def456');

        self::assertSame("line1\nline2", $result);
    }

    public function testGetStatsReturnsDto(): void
    {
        $this->dockerCli->method('getStats')->willReturn([
            'CPUPerc' => '25.50%',
            'MemUsage' => '100MiB / 1GiB',
            'MemPerc' => '10.00%',
            'NetIO' => '1.5kB / 2.3kB',
            'BlockIO' => '0B / 0B',
            'PIDs' => '42',
        ]);

        $result = $this->service->getStats('abc123def456');

        self::assertInstanceOf(ContainerStatsDto::class, $result);
        self::assertSame(25.5, $result->cpuPercent);
        self::assertSame(42, $result->pids);
    }

    public function testValidateContainerIdRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getLogs('invalid-id');
    }
}
