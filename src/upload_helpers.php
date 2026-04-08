<?php
declare(strict_types=1);

function flus_upload_ensure_directory(string $targetDir): void
{
    if (is_dir($targetDir)) {
        return;
    }

    if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('No se pudo preparar la carpeta de destino para archivos.');
    }
}

function flus_upload_stage_image(
    array $file,
    string $targetDir,
    string $prefix,
    int $maxBytes,
    array $allowedExtensions,
    array $allowedMimeTypes,
    string $label = 'archivo'
): ?array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir ' . $label . '. Codigo de error: ' . $error);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('No se encontro el archivo temporal de ' . $label . '.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('El archivo de ' . $label . ' supera el tamano permitido.');
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safeExt = $ext === 'jpeg' ? 'jpg' : $ext;
    if (!in_array($safeExt, $allowedExtensions, true)) {
        throw new RuntimeException('Formato de ' . $label . ' no permitido.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($tmpName);
    if ($mimeType === '' || !in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException('El archivo subido para ' . $label . ' no es una imagen valida.');
    }

    flus_upload_ensure_directory($targetDir);

    $filename = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExt;
    $finalPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    $stagedPath = $finalPath . '.pending';

    if (!move_uploaded_file($tmpName, $stagedPath)) {
        throw new RuntimeException('No se pudo guardar temporalmente ' . $label . '.');
    }

    return [
        'filename' => $filename,
        'final_path' => $finalPath,
        'staged_path' => $stagedPath,
        'mime_type' => $mimeType,
        'size' => $size,
    ];
}

function flus_upload_promote(array $upload): void
{
    $stagedPath = (string)($upload['staged_path'] ?? '');
    $finalPath = (string)($upload['final_path'] ?? '');
    if ($stagedPath === '' || $finalPath === '') {
        throw new RuntimeException('No hay archivo temporal listo para promover.');
    }
    if (!is_file($stagedPath)) {
        throw new RuntimeException('El archivo temporal de subida ya no existe.');
    }
    if (!rename($stagedPath, $finalPath)) {
        throw new RuntimeException('No se pudo confirmar el archivo subido.');
    }
}

function flus_upload_cleanup(?array $upload): void
{
    if (!is_array($upload)) {
        return;
    }

    foreach (['staged_path', 'final_path'] as $key) {
        $path = (string)($upload[$key] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

function flus_upload_delete_file_if_exists(?string $path): void
{
    $value = trim((string)$path);
    if ($value !== '' && is_file($value)) {
        @unlink($value);
    }
}
