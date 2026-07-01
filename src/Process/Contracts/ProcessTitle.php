<?php

declare(strict_types=1);

namespace JobWarden\Process\Contracts;

interface ProcessTitle
{
    /** Best-effort: makes `ps` readable for operators and advertises the nonce. */
    public function set(string $title): void;
}
