<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Services\AntiFraud\Strategies\IdentityFraudStrategy;
use App\Services\AntiFraud\Strategies\TransactionFraudStrategy;
use App\Services\AntiFraud\Strategies\TaskFraudStrategy;
use Core\Container;

class FraudStrategyResolver
{
    private Container $container;

    /**
     * Map of anti-fraud actions to their specialized strategy classes.
     */
    private array $map = [
        'auth.login'         => IdentityFraudStrategy::class,
        'auth.register'      => IdentityFraudStrategy::class,
        'payment.create'     => TransactionFraudStrategy::class,
        'withdrawal.create'  => TransactionFraudStrategy::class,
        'wallet.transfer'    => TransactionFraudStrategy::class,
        'crypto.deposit'     => TransactionFraudStrategy::class,
        'task.custom'        => TaskFraudStrategy::class,
        'task.social'        => TaskFraudStrategy::class,
        'task.seo'           => TaskFraudStrategy::class,
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Lazily resolve strategy class from the DI container.
     *
     * @param string $action Action key.
     * @return FraudCheckStrategyInterface|null
     */
    public function resolve(string $action): ?FraudCheckStrategyInterface
    {
        $class = $this->map[$action] ?? null;

        if (!$class) {
            return null;
        }

        return $this->container->make($class);
    }
}
