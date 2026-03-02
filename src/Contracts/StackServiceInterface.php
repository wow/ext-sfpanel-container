<?php

declare(strict_types=1);

namespace SfPanel\Ext\Containers\Contracts;

use SfPanel\Ext\Containers\Contracts\Dto\StackDto;

interface StackServiceInterface
{
    /**
     * @return StackDto[]
     */
    public function list(): array;

    public function get(string $name): StackDto;

    public function create(string $name, string $composeContent): StackDto;

    public function deploy(string $name): string;

    public function down(string $name, bool $removeVolumes = false): string;

    public function delete(string $name): string;

    public function updateComposeFile(string $name, string $content): void;

    public function getComposeFileContent(string $name): string;
}
