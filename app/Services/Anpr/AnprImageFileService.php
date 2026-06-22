<?php

namespace App\Services\Anpr;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class AnprImageFileService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'bmp', 'webp'];

    /**
     * @return list<string>
     */
    public function allowedRoots(): array
    {
        $roots = config('anpr.image_roots', []);

        return is_array($roots) && $roots !== [] ? $roots : [storage_path('app/anpr')];
    }

    public function primaryRoot(): string
    {
        return $this->allowedRoots()[0];
    }

    public function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory)) {
            return;
        }

        mkdir($directory, 0755, true);
    }

    /**
     * @return array{relative_path: string, file_size: int|null, resolution: string|null, absolute_path: string}
     */
    public function storeUploadedEvidence(
        UploadedFile $uploadedFile,
        string $anprEventId,
        string $imageType,
    ): array {
        $root = $this->primaryRoot();
        $this->ensureDirectoryExists($root);

        $extension = strtolower(
            $uploadedFile->getClientOriginalExtension()
            ?: $uploadedFile->extension()
            ?: 'jpg'
        );
        $safeExtension = in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'jpg';

        $filename = sprintf('%s_%s.%s', $imageType, Str::uuid(), $safeExtension);
        $relativeDirectory = 'events/'.$anprEventId;
        $relativePath = $relativeDirectory.'/'.$filename;

        $absoluteDirectory = rtrim($root, '\\/')
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        $this->ensureDirectoryExists($absoluteDirectory);

        $uploadedFile->move($absoluteDirectory, $filename);

        $absolutePath = $absoluteDirectory.DIRECTORY_SEPARATOR.$filename;
        $fileSize = is_file($absolutePath) ? (int) filesize($absolutePath) : null;
        $resolution = $this->resolveImageResolution($absolutePath);

        return [
            'relative_path' => str_replace('\\', '/', $relativePath),
            'file_size' => $fileSize,
            'resolution' => $resolution,
            'absolute_path' => $absolutePath,
        ];
    }

    public function deleteIfWithinAllowedRoots(string $filePath): bool
    {
        $absolutePath = $this->resolveAbsolutePath($filePath);

        if ($absolutePath === null) {
            return false;
        }

        if (! is_file($absolutePath)) {
            return false;
        }

        return @unlink($absolutePath);
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

    private function resolveImageResolution(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $dimensions = @getimagesize($absolutePath);

        if ($dimensions === false) {
            return null;
        }

        return sprintf('%dx%d', $dimensions[0], $dimensions[1]);
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
