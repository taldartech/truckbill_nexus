<?php

namespace App\Support\Files;

use App\Support\Files\Exceptions\FileValidationException;
use Illuminate\Http\UploadedFile;

class FileValidator
{
    public function validate(UploadedFile $file): void
    {
        $maxBytes = (int) config('files.max_bytes');

        if ($file->getSize() === false || $file->getSize() > $maxBytes) {
            throw new FileValidationException(
                sprintf('File exceeds the maximum allowed size of %d bytes.', $maxBytes)
            );
        }

        if ($file->getSize() <= 0) {
            throw new FileValidationException('Empty uploads are not permitted.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        /** @var list<string> $allowedExtensions */
        $allowedExtensions = config('files.allowed_extensions', []);

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw new FileValidationException('File extension is not allowed.');
        }

        /** @var array<string, list<string>> $allowedMimes */
        $allowedMimes = config('files.allowed_mimes', []);
        $allowedForExtension = $allowedMimes[$extension] ?? [];

        $detectedMime = $file->getMimeType();
        if ($detectedMime === null || $detectedMime === '') {
            $detectedMime = $file->getClientMimeType();
        }

        if ($detectedMime === '' || ! in_array($detectedMime, $allowedForExtension, true)) {
            throw new FileValidationException('File MIME type does not match an allowed type for this extension.');
        }

        $clientMime = $file->getClientMimeType();
        if ($clientMime !== '' && ! in_array($clientMime, $allowedForExtension, true)) {
            throw new FileValidationException('Client-declared MIME type is not allowed for this extension.');
        }
    }
}
