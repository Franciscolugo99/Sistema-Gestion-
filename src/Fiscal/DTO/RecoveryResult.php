<?php
declare(strict_types=1);

final class RecoveryResult
{
    public bool $ok = false;
    public string $requestUid = '';
    public ?int $ventaAnulacionId = null;
    public ?int $ncFacturaId = null;
    public ?string $message = null;
    public ?string $errorCode = null;
    public ?string $errorMessage = null;

    public static function ok(string $requestUid, ?int $ventaAnulacionId = null, ?int $ncFacturaId = null, ?string $message = null): self
    {
        $dto = new self();
        $dto->ok = true;
        $dto->requestUid = $requestUid;
        $dto->ventaAnulacionId = $ventaAnulacionId;
        $dto->ncFacturaId = $ncFacturaId;
        $dto->message = $message;
        return $dto;
    }

    public static function error(string $requestUid, ?string $errorCode = null, ?string $errorMessage = null): self
    {
        $dto = new self();
        $dto->ok = false;
        $dto->requestUid = $requestUid;
        $dto->errorCode = $errorCode;
        $dto->errorMessage = $errorMessage;
        return $dto;
    }
}
