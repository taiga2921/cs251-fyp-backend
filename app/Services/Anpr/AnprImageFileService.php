<?php

namespace App\Services\Anpr;

class AnprImageFileService
{
    /**
     * @return list<string>
     */
    public function allowedRoots(): array
    {
        $roots = config('anpr.image_roots', []);

        return is_array($roots) && $roots !== [] ? $roots : [storage_path('app/anpr')];
    }

    public function resolveAbsolutePath(string $filePath): ?string
    {
        $filePath = trim($filePath);

        if ($filePath === '') {
            return null;
        }

        $candidates = [$filePath];

        if (! $this->isAbsolutePath($filePath)) {
            foreach ($this->allowedRoots() as $root) {
                $candidates[] = rtrim($root, '\\/').DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath), DIRECTORY_SEPARATOR);
            }
        }

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveExistingFileWithinAllowedRoots($candidate);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveExistingFileWithinAllowedRoots(string $candidate): ?string
    {
        $realPath = realpath($candidate);

        if ($realPath === false || ! is_file($realPath)) {
            return null;
        }

        foreach ($this->allowedRoots() as $root) {
            $normalizedRoot = realpath($root) ?: $this->normalizePath($root);

            if ($normalizedRoot === false) {
                continue;
            }

            if ($this->pathIsWithinRoot($realPath, $normalizedRoot)) {
                return $realPath;
            }
        }

        return null;
    }

    private function pathIsWithinRoot(string $path, string $root): bool
    {
        $normalizedPath = $this->normalizePath($path);
        $normalizedRoot = rtrim($this->normalizePath($root), DIRECTORY_SEPARATOR);

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot.DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
