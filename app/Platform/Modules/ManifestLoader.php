<?php

namespace App\Platform\Modules;

use Illuminate\Support\Facades\Cache;
use Nwidart\Modules\Contracts\RepositoryInterface;

/**
 * Reads manifest.php from every installed nwidart module. The raw manifests
 * are cached and re-read automatically when any manifest file changes.
 */
class ManifestLoader
{
    public function __construct(private RepositoryInterface $modules) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function load(): array
    {
        $files = $this->manifestFiles();

        $fingerprint = md5(implode('|', array_map(
            fn (string $file) => $file.':'.filemtime($file),
            $files,
        )));

        return Cache::rememberForever('modules:manifests:'.$fingerprint, fn () => array_map(
            fn (string $file) => require $file,
            $files,
        ));
    }

    /**
     * @return list<string>
     */
    private function manifestFiles(): array
    {
        $files = [];

        foreach ($this->modules->allEnabled() as $module) {
            $file = $module->getPath().DIRECTORY_SEPARATOR.'manifest.php';

            if (is_file($file)) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }
}
