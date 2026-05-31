<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SagaStepInterface;
use Core\Container;

class SagaMethodCallStep implements SagaStepInterface
{
    private string $name;
    private string $serviceClass;
    private string $executeMethod;
    private string $compensateMethod;

    public function __construct(string $name, string $serviceClass, string $executeMethod, string $compensateMethod)
    {
        $this->name = $name;
        $this->serviceClass = $serviceClass;
        $this->executeMethod = $executeMethod;
        $this->compensateMethod = $compensateMethod;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function execute(mixed $payload): mixed
    {
        $service = Container::getInstance()->make($this->serviceClass);
        return call_user_func([$service, $this->executeMethod], $payload);
    }

    public function compensate(mixed $payload, mixed $result, \Throwable $originalError): void
    {
        $service = Container::getInstance()->make($this->serviceClass);
        call_user_func([$service, $this->compensateMethod], $payload, $result, $originalError);
    }
    
    /**
     * Needed for PHP serialization to ensure Container isn't serialized
     */
    public function __sleep()
    {
        return ['name', 'serviceClass', 'executeMethod', 'compensateMethod'];
    }
}
