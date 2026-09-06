<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Console;

use Illuminate\Console\Command;
use Jkudish\DocumentExtraction\Preparation\RuntimeDoctor;

final class ExtractionDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'extraction:doctor';

    /** @var string */
    protected $description = 'Check offline document preparation runtime capabilities';

    public function handle(RuntimeDoctor $doctor): int
    {
        $checks = $doctor->check();
        $ready = true;

        foreach ($checks as $name => $check) {
            $ready = $ready && $check['ready'];
            $line = sprintf('%s: %s', $name, $check['message']);

            if ($check['ready']) {
                $this->components->info($line);
            } else {
                $this->components->error($line);
            }
        }

        if ($ready) {
            $this->components->info('Document preparation is ready. No provider or network checks were made.');

            return self::SUCCESS;
        }

        $this->components->error('Document preparation is not ready. No provider or network checks were made.');

        return self::FAILURE;
    }
}
