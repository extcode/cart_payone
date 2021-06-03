<?php
declare(strict_types=1);
namespace Extcode\CartPayone\EventListener\Order\Payment;

/*
 * This file is part of the package extcode/cart-payone.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Event\Order\EventInterface;

class ClearCart extends \Extcode\Cart\EventListener\ProcessOrderCreate\ClearCart
{
    public function __invoke(EventInterface $event): void
    {
        $orderItem = $event->getOrderItem();

        $provider = $orderItem->getPayment()->getProvider();

        if (strpos($provider, 'PAYONE') === 0) {
            parent::__invoke($event);
        }
    }
}
