<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Tests\Unit\Application\Service;

use Wow\Ext\Containers\Application\Service\StackService;
use Wow\Ext\Containers\Infrastructure\Adapter\DockerComposeAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StackServiceTest extends TestCase
{
    private StackService $service;
    private DockerComposeAdapter&MockObject $composeAdapter;
    private MessageBusInterface&MockObject $eventBus;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->composeAdapter = $this->createMock(DockerComposeAdapter::class);
        $this->eventBus = $this->createMock(MessageBusInterface::class);
        $this->eventBus->method('dispatch')->willReturnCallback(
            fn (object $message) => new Envelope($message),
        );

        $this->tempDir = sys_get_temp_dir().'/sfpanel-test-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks', 0o755, true);

        $this->service = new StackService(
            $this->composeAdapter,
            $this->eventBus,
            new NullLogger(),
            $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
        }
    }

    public function testCreateValidatesEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stack name must not be empty');

        $this->service->create('', "services:\n  web:\n    image: nginx");
    }

    public function testCreateValidatesInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create('INVALID_NAME', "services:\n  web:\n    image: nginx");
    }

    public function testCreateValidatesEmptyComposeContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->service->create('my-stack', '');
    }

    public function testCreateValidatesInvalidYaml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid YAML');

        $this->service->create('my-stack', 'invalid: yaml: content: [}');
    }

    public function testCreateValidatesMissingServicesKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"services" key');

        $this->service->create('my-stack', "version: '3'\nnetworks:\n  default:");
    }

    public function testCreateRejectsDuplicate(): void
    {
        $stackDir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/existing';
        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', "services:\n  web:\n    image: nginx");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        $this->service->create('existing', "services:\n  web:\n    image: nginx");
    }

    public function testCreateWritesFile(): void
    {
        $this->composeAdapter->method('ps')->willReturn([]);

        $content = "services:\n  web:\n    image: nginx";
        $result = $this->service->create('my-stack', $content);

        self::assertSame('my-stack', $result->name);
        self::assertTrue(file_exists($this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack/compose.yaml'));
        self::assertSame($content, file_get_contents($this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack/compose.yaml'));
    }

    public function testGetThrowsForNonexistent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->service->get('nonexistent');
    }

    public function testGetReturnsDtoFromFilesystem(): void
    {
        $stackDir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack';
        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', "services:\n  web:\n    image: nginx");

        $this->composeAdapter->method('ps')->willReturn([]);

        $dto = $this->service->get('my-stack');

        self::assertSame('my-stack', $dto->name);
        self::assertSame($stackDir, $dto->path);
        self::assertSame('not_deployed', $dto->status);
        self::assertSame(1, $dto->serviceCount);
    }

    public function testListScansFilesystem(): void
    {
        $this->composeAdapter->method('ps')->willReturn([]);

        foreach (['alpha', 'bravo', 'charlie'] as $name) {
            $dir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/'.$name;
            mkdir($dir, 0o755, true);
            file_put_contents($dir.'/compose.yaml', "services:\n  web:\n    image: nginx");
        }

        $result = $this->service->list();

        self::assertCount(3, $result);
        self::assertSame('alpha', $result[0]->name);
        self::assertSame('bravo', $result[1]->name);
        self::assertSame('charlie', $result[2]->name);
    }

    public function testDeployCallsComposeUp(): void
    {
        $stackDir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack';
        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', "services:\n  web:\n    image: nginx");

        $this->composeAdapter->expects(self::once())
            ->method('up')
            ->with($stackDir, 'sfpanel-stack-my-stack')
            ->willReturn('Container my-stack-web-1 Started');

        $this->eventBus->expects(self::once())->method('dispatch');

        $output = $this->service->deploy('my-stack');

        self::assertStringContainsString('Started', $output);
    }

    public function testDownCallsComposeDown(): void
    {
        $stackDir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack';
        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', "services:\n  web:\n    image: nginx");

        $this->composeAdapter->expects(self::once())
            ->method('down')
            ->with($stackDir, 'sfpanel-stack-my-stack', false)
            ->willReturn('Container my-stack-web-1 Removed');

        $output = $this->service->down('my-stack');

        self::assertStringContainsString('Removed', $output);
    }

    public function testDeleteRemovesDirectory(): void
    {
        $stackDir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack';
        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', "services:\n  web:\n    image: nginx");

        $this->composeAdapter->expects(self::once())
            ->method('down')
            ->willReturn('Container my-stack-web-1 Removed');

        $output = $this->service->delete('my-stack');

        self::assertDirectoryDoesNotExist($stackDir);
        self::assertStringContainsString('Removed', $output);
    }

    public function testGetComposeFileContentReturnsContent(): void
    {
        $stackDir = $this->tempDir.'/mnt/ext/wow/ext-sfpanel-container/stacks/my-stack';
        mkdir($stackDir, 0o755, true);
        file_put_contents($stackDir.'/compose.yaml', "services:\n  web:\n    image: nginx");

        $content = $this->service->getComposeFileContent('my-stack');

        self::assertStringContainsString('nginx', $content);
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
