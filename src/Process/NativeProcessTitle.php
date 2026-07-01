<?php

declare(strict_types=1);

namespace JobWarden\Process;

use JobWarden\Process\Contracts\ProcessTitle;

final class NativeProcessTitle implements ProcessTitle
{
    public function set(string $title): void
    {
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }
}
