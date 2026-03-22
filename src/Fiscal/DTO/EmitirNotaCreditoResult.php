<?php
declare(strict_types=1);

final class EmitirNotaCreditoResult
{
    public bool $approved = false;
    public bool $rejected = false;
    public string $requestUid = '';
    public string $scope = 'TOTAL';
    public ?string $errorCode = null;
    public ?string $errorMessage = null;

    /** @var array<string,mixed> */
    public array $facturaHeader = [];

    /** @var array<int,array<string,mixed>> */
    public array $facturaItems = [];

    /** @var array<string,mixed> */
    public array $rawRequest = [];

    /** @var array<string,mixed> */
    public array $rawResponse = [];

    public static function rejected(string $requestUid, string $scope, ?string $errorCode, ?string $errorMessage): self
    {
        $dto = new self();
        $dto->rejected = true;
        $dto->requestUid = $requestUid;
        $dto->scope = $scope;
        $dto->errorCode = $errorCode;
        $dto->errorMessage = $errorMessage;
        return $dto;
    }

    /**
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $rawRequest
     * @param array<string,mixed> $rawResponse
     */
    public static function approved(string $requestUid, string $scope, array $header, array $items, array $rawRequest = [], array $rawResponse = []): self
    {
        $dto = new self();
        $dto->approved = true;
        $dto->requestUid = $requestUid;
        $dto->scope = $scope;
        $dto->facturaHeader = $header;
        $dto->facturaItems = $items;
        $dto->rawRequest = $rawRequest;
        $dto->rawResponse = $rawResponse;
        return $dto;
    }
}
