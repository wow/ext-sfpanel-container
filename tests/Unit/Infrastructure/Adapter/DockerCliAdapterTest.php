<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Tests\Unit\Infrastructure\Adapter;

use Wow\Ext\Containers\Infrastructure\Adapter\DockerCliAdapter;
use App\Shared\Infrastructure\Command\CommandExecutorInterface;
use App\Shared\Infrastructure\Command\CommandResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DockerCliAdapterTest extends TestCase
{
    private DockerCliAdapter $adapter;
    private CommandExecutorInterface&MockObject $executor;

    protected function setUp(): void
    {
        $this->executor = $this->createMock(CommandExecutorInterface::class);
        $this->adapter = new DockerCliAdapter($this->executor, new NullLogger());
    }

    public function testListContainersParsesJsonLines(): void
    {
        $jsonLine1 = json_encode(['ID' => 'abc123def456', 'Names' => 'my-app', 'State' => 'running', 'Labels' => '', 'Image' => 'nginx']);
        $jsonLine2 = json_encode(['ID' => 'def456abc789', 'Names' => 'my-db', 'State' => 'exited', 'Labels' => '', 'Image' => 'postgres']);

        $this->executor->method('execute')->willReturn(
            new CommandResult(0, $jsonLine1."\n".$jsonLine2, ''),
        );

        $containers = $this->adapter->listContainers();

        self::assertCount(2, $containers);
        self::assertSame('abc123def456', $containers[0]['ID']);
        self::assertSame('def456abc789', $containers[1]['ID']);
        self::assertFalse($containers[0]['isSfPanel']);
    }

    public function testListContainersDetectsSfPanelByLabel(): void
    {
        $json = json_encode([
            'ID' => 'abc123def456',
            'Names' => 'sfpanel',
            'State' => 'running',
            'Labels' => 'com.docker.compose.project=sfpanel,com.docker.compose.service=sfpanel',
            'Image' => 'sfpanel:latest',
        ]);

        $this->executor->method('execute')->willReturn(
            new CommandResult(0, $json, ''),
        );

        $containers = $this->adapter->listContainers();

        self::assertCount(1, $containers);
        self::assertTrue($containers[0]['isSfPanel']);
    }

    public function testListContainersFiltersRestartHelpers(): void
    {
        $helper = json_encode(['ID' => 'aaa111bbb222', 'Names' => 'sfpanel-restart-abc12345', 'State' => 'running', 'Labels' => '', 'Image' => 'docker:cli']);
        $normal = json_encode(['ID' => 'ccc333ddd444', 'Names' => 'my-app', 'State' => 'running', 'Labels' => '', 'Image' => 'nginx']);

        $this->executor->method('execute')->willReturn(
            new CommandResult(0, $helper."\n".$normal, ''),
        );

        $containers = $this->adapter->listContainers();

        self::assertCount(1, $containers);
        self::assertSame('my-app', $containers[0]['Names']);
    }

    public function testListContainersThrowsOnFailure(): void
    {
        $this->executor->method('execute')->willReturn(
            new CommandResult(1, '', 'Cannot connect to Docker daemon'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to list Docker containers');

        $this->adapter->listContainers();
    }

    public function testInspectContainerReturnsData(): void
    {
        $inspectData = ['Id' => 'abc123def456', 'Name' => '/my-app', 'State' => ['Status' => 'running']];

        $this->executor->method('execute')->willReturn(
            new CommandResult(0, json_encode($inspectData), ''),
        );

        $result = $this->adapter->inspectContainer('abc123def456');

        self::assertSame('abc123def456', $result['Id']);
    }

    public function testInspectContainerRejectsInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid container ID');

        $this->adapter->inspectContainer('not-a-valid-id!');
    }

    public function testGetLogsReturnsOutput(): void
    {
        $this->executor->method('execute')->willReturn(
            new CommandResult(0, "log line 1\nlog line 2", 'stderr output'),
        );

        $logs = $this->adapter->getLogs('abc123def456', 100);

        self::assertStringContainsString('log line 1', $logs);
        self::assertStringContainsString('stderr output', $logs);
    }

    public function testStartCallsDockerStart(): void
    {
        $this->executor->expects(self::once())
            ->method('execute')
            ->with(['docker', 'start', 'abc123def456'])
            ->willReturn(new CommandResult(0, 'abc123def456', ''));

        $this->adapter->start('abc123def456');
    }

    public function testStopCallsDockerStop(): void
    {
        $this->executor->expects(self::once())
            ->method('execute')
            ->with(['docker', 'stop', 'abc123def456'])
            ->willReturn(new CommandResult(0, 'abc123def456', ''));

        $this->adapter->stop('abc123def456');
    }

    public function testRestartCallsDockerRestart(): void
    {
        $this->executor->expects(self::once())
            ->method('execute')
            ->with(['docker', 'restart', 'abc123def456'])
            ->willReturn(new CommandResult(0, 'abc123def456', ''));

        $this->adapter->restart('abc123def456');
    }

    public function testRemoveCallsDockerRm(): void
    {
        $this->executor->expects(self::once())
            ->method('execute')
            ->with(['docker', 'rm', 'abc123def456'])
            ->willReturn(new CommandResult(0, 'abc123def456', ''));

        $this->adapter->remove('abc123def456');
    }

    public function testRemoveWithForceCallsDockerRmF(): void
    {
        $this->executor->expects(self::once())
            ->method('execute')
            ->with(['docker', 'rm', '-f', 'abc123def456'])
            ->willReturn(new CommandResult(0, 'abc123def456', ''));

        $this->adapter->remove('abc123def456', force: true);
    }
}
