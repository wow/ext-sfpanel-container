<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContainerControllerTest extends WebTestCase
{
    public function testContainerListRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/containers');

        self::assertResponseRedirects('/login');
    }

    public function testStackListRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/containers/stacks');

        self::assertResponseRedirects('/login');
    }

    public function testCreateStackRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/containers/stacks/new');

        self::assertResponseRedirects('/login');
    }

    public function testContainerActionRejectsGetMethod(): void
    {
        $client = static::createClient();
        $client->request('GET', '/containers/abc123def456/start');

        self::assertResponseStatusCodeSame(405);
    }

    public function testContainerActionRejectsInvalidAction(): void
    {
        $client = static::createClient();
        $client->request('POST', '/containers/abc123def456/destroy');

        self::assertResponseStatusCodeSame(404);
    }

    public function testContainerShowRejectsInvalidId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/containers/not-a-valid-id');

        self::assertResponseStatusCodeSame(404);
    }

    public function testContainerLogsRejectsInvalidId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/containers/invalid/logs');

        self::assertResponseStatusCodeSame(404);
    }
}
