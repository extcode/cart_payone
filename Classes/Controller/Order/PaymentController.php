<?php

namespace Extcode\CartPayone\Controller\Order;

/*
 * This file is part of the package extcode/cart-payone.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Domain\Model\Cart;
use Extcode\Cart\Domain\Repository\CartRepository;
use Extcode\Cart\Domain\Repository\Order\PaymentRepository;
use Extcode\Cart\Service\SessionHandler;
use Extcode\CartPayone\Event\Order\CancelEvent;
use Extcode\CartPayone\Event\Order\FinishEvent;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PaymentController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var SessionHandler
     */
    protected $sessionHandler;

    /**
     * @var CartRepository
     */
    protected $cartRepository;

    /**
     * @var PaymentRepository
     */
    protected $paymentRepository;

    /**
     * @var Cart
     */
    protected $cart;

    /**
     * @var array
     */
    protected $cartPluginSettings;

    /**
     * @var array
     */
    protected $pluginSettings;

    public function injectPersistenceManager(PersistenceManager $persistenceManager): void
    {
        $this->persistenceManager = $persistenceManager;
    }

    public function injectSessionHandler(SessionHandler $sessionHandler): void
    {
        $this->sessionHandler = $sessionHandler;
    }

    public function injectCartRepository(CartRepository $cartRepository): void
    {
        $this->cartRepository = $cartRepository;
    }

    public function injectPaymentRepository(PaymentRepository $paymentRepository): void
    {
        $this->paymentRepository = $paymentRepository;
    }

    protected function initializeAction(): void
    {
        $this->cartPluginSettings =
            $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'Cart'
            );

        $this->pluginSettings =
            $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'CartPayone'
            );
    }

    public function successAction(): void
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $hash = $this->request->getArgument('hash');

            $querySettings = $this->cartRepository->createQuery()->getQuerySettings();
            $querySettings->setStoragePageIds([$this->cartPluginSettings['settings']['order']['pid']]);
            $this->cartRepository->setDefaultQuerySettings($querySettings);

            $this->cart = $this->cartRepository->findOneBySHash($hash);

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();
                $payment = $orderItem->getPayment();

                if ($payment->getStatus() !== 'paid') {
                    $payment->setStatus('paid');

                    $this->paymentRepository->update($payment);
                    $this->persistenceManager->persistAll();

                    $orderItem = $this->cart->getOrderItem();
                    $finishEvent = new FinishEvent($this->cart->getCart(), $orderItem, $this->cartPluginSettings);
                    $this->eventDispatcher->dispatch($finishEvent);
                }

                $this->redirect('show', 'Cart\Order', 'Cart', ['orderItem' => $orderItem]);
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpayone.controller.order.payment.action.success.error_occured',
                        'CartPayone'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpayone.controller.order.payment.action.success.access_denied',
                    'CartPayone'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
    }

    public function cancelAction(): void
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $hash = $this->request->getArgument('hash');

            $querySettings = $this->cartRepository->createQuery()->getQuerySettings();
            $querySettings->setStoragePageIds([$this->cartPluginSettings['settings']['order']['pid']]);
            $this->cartRepository->setDefaultQuerySettings($querySettings);

            $this->cart = $this->cartRepository->findOneByFHash($hash);

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();
                $payment = $orderItem->getPayment();

                $this->restoreCartSession();

                if ($payment->getStatus() !== 'canceled') {
                    $payment->setStatus('canceled');

                    $this->paymentRepository->update($payment);
                    $this->persistenceManager->persistAll();

                    $orderItem = $this->cart->getOrderItem();
                    $finishEvent = new CancelEvent($this->cart->getCart(), $orderItem, $this->cartPluginSettings);
                    $this->eventDispatcher->dispatch($finishEvent);
                }

                $this->addFlashMessageToCartCart('tx_cartpayone.controller.order.payment.action.cancel.successfully_canceled');

                $this->redirect('show', 'Cart\Cart', 'Cart');
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpayone.controller.order.payment.action.cancel.error_occured',
                        'CartPayone'
                    ),
                    '',
                    AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpayone.controller.order.payment.action.cancel.access_denied',
                    'CartPayone'
                ),
                '',
                AbstractMessage::ERROR
            );
        }
    }

    protected function addFlashMessageToCartCart(string $translationKey): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            LocalizationUtility::translate(
                $translationKey,
                'CartPayone'
            ),
            '',
            AbstractMessage::ERROR,
            true
        );

        $flashMessageService = new FlashMessageService();
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier('extbase.flashmessages.tx_cart_cart');
        $messageQueue->enqueue($flashMessage);
    }

    protected function restoreCartSession(): void
    {
        $cart = $this->cart->getCart();
        $cart->resetOrderNumber();
        $cart->resetInvoiceNumber();
        $this->sessionHandler->write($cart, $this->cartPluginSettings['settings']['cart']['pid']);
    }
}
