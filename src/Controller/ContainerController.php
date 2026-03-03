<?php

declare(strict_types=1);

namespace Wow\Ext\Containers\Controller;

use Wow\Ext\Containers\Contracts\ContainerServiceInterface;
use Wow\Ext\Containers\Contracts\StackServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

#[Route('/containers')]
final class ContainerController extends AbstractController
{
    // ── Container list ───────────────────────────────────────────────

    #[Route('', name: 'panel_containers', methods: ['GET'])]
    public function index(ContainerServiceInterface $containerService): Response
    {
        return $this->render('@containers/panel/containers/index.html.twig', [
            'containers' => $containerService->list(),
        ]);
    }

    // ── Stacks (specific routes before wildcard) ─────────────────────

    #[Route('/stacks', name: 'panel_container_stacks', methods: ['GET'])]
    public function stacks(StackServiceInterface $stackService): Response
    {
        return $this->render('@containers/panel/containers/stacks/index.html.twig', [
            'stacks' => $stackService->list(),
        ]);
    }

    #[Route('/stacks/new', name: 'panel_container_stacks_new', methods: ['GET'])]
    public function newStack(): Response
    {
        return $this->render('@containers/panel/containers/stacks/create.html.twig');
    }

    #[Route('/stacks', name: 'panel_container_stacks_create', methods: ['POST'])]
    public function createStack(Request $request, StackServiceInterface $stackService): Response
    {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('stack_create', $request->request->getString('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.']);
            }
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_container_stacks');
        }

        $name = trim($request->request->getString('name'));
        $content = $request->request->getString('compose_content');
        $deployAfter = $request->request->getBoolean('deploy_after');

        if ('' === $name || '' === $content) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Stack name and compose file content are required.']);
            }
            $this->addFlash('error', 'Stack name and compose file content are required.');

            return $this->redirectToRoute('panel_container_stacks_new');
        }

        try {
            $stackService->create($name, $content);
            $output = '';

            if ($deployAfter) {
                $output = $stackService->deploy($name);
            }

            if ($isAjax) {
                return new JsonResponse(['success' => true, 'output' => $output]);
            }

            $this->addFlash('success', $deployAfter ? 'Stack created and deployed successfully.' : 'Stack created successfully.');
        } catch (\InvalidArgumentException $e) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('panel_container_stacks_new');
        } catch (\Throwable $e) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Deploy failed: '.$e->getMessage()]);
            }
            $this->addFlash('error', 'Deploy failed: '.$e->getMessage());

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
    }

    #[Route('/stacks/{name}', name: 'panel_container_stacks_show', methods: ['GET'])]
    public function showStack(string $name, StackServiceInterface $stackService): Response
    {
        try {
            $stack = $stackService->get($name);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $composeContent = $stackService->getComposeFileContent($name);

        return $this->render('@containers/panel/containers/stacks/show.html.twig', [
            'stack' => $stack,
            'composeContent' => $composeContent,
            'portsByService' => $this->parsePortsByService($composeContent),
        ]);
    }

    #[Route('/stacks/{name}/edit', name: 'panel_container_stacks_edit', methods: ['GET'])]
    public function editStack(string $name, StackServiceInterface $stackService): Response
    {
        try {
            $stack = $stackService->get($name);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        return $this->render('@containers/panel/containers/stacks/edit.html.twig', [
            'stack' => $stack,
            'composeContent' => $stackService->getComposeFileContent($name),
        ]);
    }

    #[Route('/stacks/{name}/edit', name: 'panel_container_stacks_save', methods: ['POST'])]
    public function saveStack(string $name, Request $request, StackServiceInterface $stackService): Response
    {
        if (!$this->isCsrfTokenValid('stack_edit_'.$name, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_container_stacks_edit', ['name' => $name]);
        }

        $content = $request->request->getString('compose_content');

        try {
            $stackService->updateComposeFile($name, $content);
            $this->addFlash('success', 'Compose file updated successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('panel_container_stacks_edit', ['name' => $name]);
        }

        return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
    }

    #[Route('/stacks/{name}/deploy', name: 'panel_container_stacks_deploy', methods: ['POST'])]
    public function deployStack(string $name, Request $request, StackServiceInterface $stackService): Response
    {
        set_time_limit(0);
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('stack_deploy_'.$name, $request->request->getString('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.']);
            }
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        try {
            $output = $stackService->deploy($name);
            if ($isAjax) {
                return new JsonResponse(['success' => true, 'output' => $output]);
            }
            $this->addFlash('success', 'Stack deployed successfully.');
        } catch (\Throwable $e) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
    }

    #[Route('/stacks/{name}/down', name: 'panel_container_stacks_down', methods: ['POST'])]
    public function downStack(string $name, Request $request, StackServiceInterface $stackService): Response
    {
        set_time_limit(0);
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('stack_down_'.$name, $request->request->getString('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.']);
            }
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        $removeVolumes = $request->request->getBoolean('remove_volumes');

        try {
            $output = $stackService->down($name, $removeVolumes);
            if ($isAjax) {
                return new JsonResponse(['success' => true, 'output' => $output]);
            }
            $this->addFlash('success', 'Stack stopped successfully.');
        } catch (\Throwable $e) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
    }

    #[Route('/stacks/{name}/ports', name: 'panel_container_stacks_ports_save', methods: ['POST'])]
    public function saveStackPorts(string $name, Request $request, StackServiceInterface $stackService): Response
    {
        if (!$this->isCsrfTokenValid('stack_ports_'.$name, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        $portsJson = $request->request->getString('ports_data');
        $portsData = json_decode($portsJson, true);

        if (!\is_array($portsData)) {
            $this->addFlash('error', 'Invalid port data.');

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        $composeContent = $stackService->getComposeFileContent($name);

        try {
            /** @var array<string, mixed> $config */
            $config = Yaml::parse($composeContent) ?? [];
        } catch (\Throwable) {
            $this->addFlash('error', 'Failed to parse compose file.');

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        if (!isset($config['services']) || !\is_array($config['services'])) {
            $this->addFlash('error', 'No services found in compose file.');

            return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
        }

        $existing = $this->parsePortsByService($composeContent);

        foreach ($portsData as $serviceName => $ports) {
            if (!isset($config['services'][$serviceName])) {
                continue;
            }

            $complexPorts = [];
            if (isset($existing[$serviceName])) {
                foreach ($existing[$serviceName] as $p) {
                    if ($p['complex']) {
                        $complexPorts[] = $p['raw'];
                    }
                }
            }

            $newPorts = [];
            foreach ($complexPorts as $raw) {
                $newPorts[] = $raw;
            }
            foreach ($ports as $port) {
                $spec = $this->buildPortSpec($port);
                if ('' !== $spec) {
                    $newPorts[] = $spec;
                }
            }

            if ([] === $newPorts) {
                unset($config['services'][$serviceName]['ports']);
            } else {
                $config['services'][$serviceName]['ports'] = $newPorts;
            }
        }

        try {
            $stackService->updateComposeFile($name, Yaml::dump($config, 4, 2));
            $this->addFlash('success', 'stacks.ports.save_success');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_container_stacks_show', ['name' => $name]);
    }

    #[Route('/stacks/{name}', name: 'panel_container_stacks_delete', methods: ['DELETE'])]
    public function deleteStack(string $name, Request $request, StackServiceInterface $stackService): Response
    {
        set_time_limit(0);
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('stack_delete_'.$name, $request->request->getString('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.']);
            }
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_container_stacks');
        }

        try {
            $output = $stackService->delete($name);
            if ($isAjax) {
                return new JsonResponse(['success' => true, 'output' => $output]);
            }
            $this->addFlash('success', 'Stack deleted successfully.');
        } catch (\Throwable $e) {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_container_stacks');
    }

    // ── Container detail & actions (wildcard routes last) ────────────

    #[Route('/{id}', name: 'panel_container_show', methods: ['GET'], requirements: ['id' => '[a-f0-9]{12,64}'])]
    public function show(string $id, ContainerServiceInterface $containerService): Response
    {
        try {
            $container = $containerService->get($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        return $this->render('@containers/panel/containers/show.html.twig', [
            'container' => $container,
        ]);
    }

    #[Route('/{id}/{action}', name: 'panel_container_action', methods: ['POST'], requirements: ['id' => '[a-f0-9]{12,64}', 'action' => 'start|stop|restart'])]
    public function containerAction(string $id, string $action, Request $request, ContainerServiceInterface $containerService): Response
    {
        if (!$this->isCsrfTokenValid('container_action_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_containers');
        }

        try {
            match ($action) {
                'start' => $containerService->start($id),
                'stop' => $containerService->stop($id),
                'restart' => $containerService->restart($id),
                default => throw new \InvalidArgumentException(\sprintf('Unknown action: %s', $action)),
            };
            $this->addFlash('success', \sprintf('Container %s action completed.', $action));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_container_show', ['id' => $id]);
    }

    #[Route('/{id}', name: 'panel_container_remove', methods: ['DELETE'], requirements: ['id' => '[a-f0-9]{12,64}'])]
    public function removeContainer(string $id, Request $request, ContainerServiceInterface $containerService): Response
    {
        if (!$this->isCsrfTokenValid('container_remove_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('panel_containers');
        }

        try {
            $containerService->remove($id);
            $this->addFlash('success', 'Container removed successfully.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('panel_containers');
    }

    #[Route('/{id}/logs', name: 'panel_container_logs', methods: ['GET'], requirements: ['id' => '[a-f0-9]{12,64}'])]
    public function logs(string $id, Request $request, ContainerServiceInterface $containerService): JsonResponse
    {
        $tail = $request->query->getInt('tail', 200);
        $timestamps = $request->query->getBoolean('timestamps');

        try {
            $output = $containerService->getLogs($id, $tail, $timestamps);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid container ID.'], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['logs' => $output]);
    }

    #[Route('/{id}/logs/stream', name: 'panel_container_logs_stream', methods: ['GET'], requirements: ['id' => '[a-f0-9]{12,64}'])]
    public function logsStream(string $id, Request $request, ContainerServiceInterface $containerService): StreamedResponse
    {
        $tail = $request->query->getInt('tail', 200);
        $timestamps = $request->query->getBoolean('timestamps');
        $interval = max(2, min($request->query->getInt('interval', 3), 30));

        // Release session lock before streaming to avoid blocking other requests
        if (\PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        set_time_limit(0);

        return new StreamedResponse(function () use ($id, $tail, $timestamps, $interval, $containerService): void {
            while (!connection_aborted()) {
                try {
                    $output = $containerService->getLogs($id, $tail, $timestamps);
                    $payload = json_encode(['logs' => $output], \JSON_THROW_ON_ERROR);
                    echo "data: {$payload}\n\n";
                } catch (\Throwable $e) {
                    echo 'data: '.json_encode(['error' => $e->getMessage()])."\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                sleep($interval);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    #[Route('/{id}/stats', name: 'panel_container_stats', methods: ['GET'], requirements: ['id' => '[a-f0-9]{12,64}'])]
    public function stats(string $id, ContainerServiceInterface $containerService): JsonResponse
    {
        try {
            $stats = $containerService->getStats($id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid container ID.'], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'cpuPercent' => $stats->cpuPercent,
            'memoryUsage' => $stats->memoryUsage,
            'memoryLimit' => $stats->memoryLimit,
            'memoryPercent' => $stats->memoryPercent,
            'networkRx' => $stats->networkRx,
            'networkTx' => $stats->networkTx,
            'blockRead' => $stats->blockRead,
            'blockWrite' => $stats->blockWrite,
            'pids' => $stats->pids,
        ]);
    }

    #[Route('/{id}/stats/stream', name: 'panel_container_stats_stream', methods: ['GET'], requirements: ['id' => '[a-f0-9]{12,64}'])]
    public function statsStream(string $id, Request $request, ContainerServiceInterface $containerService): StreamedResponse
    {
        $interval = max(2, min($request->query->getInt('interval', 3), 30));

        // Release session lock before streaming to avoid blocking other requests
        if (\PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        set_time_limit(0);

        return new StreamedResponse(function () use ($id, $interval, $containerService): void {
            while (!connection_aborted()) {
                try {
                    $stats = $containerService->getStats($id);
                    $payload = json_encode([
                        'cpuPercent' => $stats->cpuPercent,
                        'memoryUsage' => $stats->memoryUsage,
                        'memoryLimit' => $stats->memoryLimit,
                        'memoryPercent' => $stats->memoryPercent,
                        'networkRx' => $stats->networkRx,
                        'networkTx' => $stats->networkTx,
                        'blockRead' => $stats->blockRead,
                        'blockWrite' => $stats->blockWrite,
                        'pids' => $stats->pids,
                    ], \JSON_THROW_ON_ERROR);

                    echo "data: {$payload}\n\n";
                } catch (\Throwable $e) {
                    echo 'data: '.json_encode(['error' => $e->getMessage()])."\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                sleep($interval);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ── Port parsing helpers ──────────────────────────────────────────

    /**
     * @return array<string, list<array{host: string, container: string, protocol: string, raw: string, complex: bool}>>
     */
    private function parsePortsByService(string $composeContent): array
    {
        try {
            /** @var array<string, mixed> $config */
            $config = Yaml::parse($composeContent) ?? [];
        } catch (\Throwable) {
            return [];
        }

        if (!isset($config['services']) || !\is_array($config['services'])) {
            return [];
        }

        $result = [];
        foreach ($config['services'] as $serviceName => $serviceConfig) {
            $result[(string)$serviceName] = [];
            if (!isset($serviceConfig['ports']) || !\is_array($serviceConfig['ports'])) {
                continue;
            }
            foreach ($serviceConfig['ports'] as $portSpec) {
                $result[(string)$serviceName][] = $this->parsePortSpec((string)$portSpec);
            }
        }

        return $result;
    }

    /**
     * @return array{host: string, container: string, protocol: string, raw: string, complex: bool}
     */
    private function parsePortSpec(string $spec): array
    {
        $raw = $spec;
        $protocol = 'tcp';

        if (str_contains($spec, '/')) {
            $parts = explode('/', $spec);
            $protocol = $parts[1];
            $spec = $parts[0];
        }

        $colonParts = explode(':', $spec);

        // IP-bound (e.g. 127.0.0.1:3306:3306) or range (e.g. 8000-8010:8000-8010)
        if (\count($colonParts) > 2 || str_contains($spec, '-')) {
            return ['host' => '', 'container' => '', 'protocol' => $protocol, 'raw' => $raw, 'complex' => true];
        }

        if (2 === \count($colonParts)) {
            return ['host' => $colonParts[0], 'container' => $colonParts[1], 'protocol' => $protocol, 'raw' => $raw, 'complex' => false];
        }

        // Container port only
        return ['host' => '', 'container' => $colonParts[0], 'protocol' => $protocol, 'raw' => $raw, 'complex' => false];
    }

    /**
     * @param array{host?: string, container?: string, protocol?: string} $port
     */
    private function buildPortSpec(array $port): string
    {
        $host = trim($port['host'] ?? '');
        $container = trim($port['container'] ?? '');
        $protocol = $port['protocol'] ?? 'tcp';

        if ('' === $container) {
            return '';
        }

        $spec = '' !== $host ? $host.':'.$container : $container;

        if ('udp' === $protocol) {
            $spec .= '/udp';
        }

        return $spec;
    }
}
