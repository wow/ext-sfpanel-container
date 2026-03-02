<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Application\Handler;

use SfPanel\Ext\Containers\Application\Message\PublishContainerListMessage;
use SfPanel\Ext\Containers\Contracts\ContainerServiceInterface;
use App\Shared\Attribute\AI\Describe;
use App\Shared\Infrastructure\Mercure\MercurePublisher;
use SfPanel\Ext\Containers\ContainerTopics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[Describe('Collects container list and publishes Turbo Stream updates via Mercure', module: 'Container', layer: 'Application')]
#[AsMessageHandler]
final readonly class PublishContainerListHandler
{
    public function __construct(
        private ContainerServiceInterface $containerService,
        private MercurePublisher $mercurePublisher,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishContainerListMessage $message): void
    {
        try {
            $containers = $this->containerService->list();

            $html = $this->twig->render('@containers/streams/containers/list.stream.html.twig', [
                'containers' => $containers,
            ]);

            $this->mercurePublisher->publish(ContainerTopics::CONTAINERS, $html);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish container list stream', ['error' => $e->getMessage()]);
        }
    }
}
