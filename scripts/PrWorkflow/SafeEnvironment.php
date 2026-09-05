<?php

declare(strict_types=1);

namespace Jkudish\DocumentExtraction\Dev\PrWorkflow;

final class SafeEnvironment
{
    /**
     * @param  array<string, string>  $ambient
     * @return array<string, string>
     */
    public static function verification(array $ambient, string $home, string $matrixPath): array
    {
        $environment = [
            'HOME' => $home,
            'COMPOSER_HOME' => $home.'/.composer',
            'COMPOSER_CACHE_DIR' => $home.'/.composer/cache',
            'COMPOSER_NO_INTERACTION' => '1',
            'XDG_CACHE_HOME' => $home.'/.cache',
            'TMPDIR' => $home.'/tmp',
            'APP_ENV' => 'testing',
            'CI' => '1',
            'PAO_DISABLE' => '1',
            'LDE_MATRIX_EVIDENCE' => $matrixPath,
        ];

        foreach (['PATH', 'SSL_CERT_FILE', 'SSL_CERT_DIR'] as $name) {
            if (isset($ambient[$name])) {
                $environment[$name] = $ambient[$name];
            }
        }

        return $environment;
    }

    /**
     * @param  array<string, string>  $ambient
     * @return array<string, string>
     */
    public static function github(array $ambient, string $token, string $repository): array
    {
        $environment = [
            'GH_TOKEN' => $token,
            'GH_PROMPT_DISABLED' => '1',
            'GH_HOST' => 'github.com',
            'GH_REPO' => $repository,
        ];

        foreach (['PATH', 'HOME', 'XDG_CONFIG_HOME', 'GH_CONFIG_DIR', 'SSL_CERT_FILE', 'SSL_CERT_DIR'] as $name) {
            if (isset($ambient[$name])) {
                $environment[$name] = $ambient[$name];
            }
        }

        return $environment;
    }
}
