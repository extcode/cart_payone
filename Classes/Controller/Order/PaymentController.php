<?php

namespace Extcode\CartPayone\Controller\Order;

use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class PaymentController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager
     */
    protected $persistenceManager;

    /**
     * Session Handler
     *
     * @var \Extcode\Cart\Service\SessionHandler
     */
    protected $sessionHandler;

    /**
     * @var \Extcode\Cart\Domain\Repository\CartRepository
     */
    protected $cartRepository;

    /**
     * @var \Extcode\Cart\Domain\Repository\Order\PaymentRepository
     */
    protected $paymentRepository;

    /**
     * @var \Extcode\Cart\Domain\Model\Cart
     */
    protected $cart = null;

    /**
     * @var array
     */
    protected $cartPluginSettings;

    /**
     * @var array
     */
    protected $pluginSettings;

    /**
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager $persistenceManager
     */
    public function injectPersistenceManager(
        \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager $persistenceManager
    ) {
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * @param \Extcode\Cart\Service\SessionHandler $sessionHandler
     */
    public function injectSessionHandler(
        \Extcode\Cart\Service\SessionHandler $sessionHandler
    ) {
        $this->sessionHandler = $sessionHandler;
    }

    /**
     * @param \Extcode\Cart\Domain\Repository\CartRepository $cartRepository
     */
    public function injectCartRepository(
        \Extcode\Cart\Domain\Repository\CartRepository $cartRepository
    ) {
        $this->cartRepository = $cartRepository;
    }

    /**
     * @param \Extcode\Cart\Domain\Repository\Order\PaymentRepository $paymentRepository
     */
    public function injectPaymentRepository(
        \Extcode\Cart\Domain\Repository\Order\PaymentRepository $paymentRepository
    ) {
        $this->paymentRepository = $paymentRepository;
    }

    /**
     * Initialize Action
     */
    protected function initializeAction()
    {
        $this->cartPluginSettings =
            $this->configurationManager->getConfiguration(
                \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'Cart'
            );

        $this->pluginSettings =
            $this->configurationManager->getConfiguration(
                \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'CartPayone'
            );
    }

    /**
     * Success Action
     */
    public function successAction()
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $hash = $this->request->getArgument('hash');

            $querySettings = $this->objectManager->get(
                \TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings::class
            );
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

                    $this->invokeFinishers($orderItem, 'success');
                }

                $this->redirect('show', 'Cart\Order', 'Cart', ['orderItem' => $orderItem]);
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpayone.controller.order.payment.action.success.error_occured',
                        $this->extensionName
                    ),
                    '',
                    \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpayone.controller.order.payment.action.success.access_denied',
                    $this->extensionName
                ),
                '',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
            );
        }
    }

    /**
     * Cancel Action
     */
    public function cancelAction()
    {
        if ($this->request->hasArgument('hash') && !empty($this->request->getArgument('hash'))) {
            $hash = $this->request->getArgument('hash');

            $querySettings = $this->objectManager->get(
                \TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings::class
            );
            $querySettings->setStoragePageIds([$this->cartPluginSettings['settings']['order']['pid']]);
            $this->cartRepository->setDefaultQuerySettings($querySettings);

            $this->cart = $this->cartRepository->findOneByFHash($hash);

            if ($this->cart) {
                $orderItem = $this->cart->getOrderItem();
                $payment = $orderItem->getPayment();

                $payment->setStatus('canceled');

                $this->paymentRepository->update($payment);
                $this->persistenceManager->persistAll();

                $this->addFlashMessageToCartCart('tx_cartpayone.controller.order.payment.action.cancel.successfully_canceled');

                $this->redirect('show', 'Cart\Cart', 'Cart');
            } else {
                $this->addFlashMessage(
                    LocalizationUtility::translate(
                        'tx_cartpayone.controller.order.payment.action.cancel.error_occured',
                        $this->extensionName
                    ),
                    '',
                    \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
                );
            }
        } else {
            $this->addFlashMessage(
                LocalizationUtility::translate(
                    'tx_cartpayone.controller.order.payment.action.cancel.access_denied',
                    $this->extensionName
                ),
                '',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
            );
        }
    }

    /**
     * Executes all finishers of this form
     *
     * @param \Extcode\Cart\Domain\Model\Order\Item $orderItem
     * @param string $returnStatus
     */
    protected function invokeFinishers(\Extcode\Cart\Domain\Model\Order\Item $orderItem, string $returnStatus)
    {
        $cart = $this->sessionHandler->restore($this->cartPluginSettings['settings']['cart']['pid']);

        $finisherContext = $this->objectManager->get(
            \Extcode\Cart\Domain\Finisher\FinisherContext::class,
            $this->cartPluginSettings,
            $cart,
            $orderItem,
            $this->getControllerContext()
        );

        if (is_array($this->pluginSettings['finishers']) &&
            is_array($this->pluginSettings['finishers']['order']) &&
            is_array($this->pluginSettings['finishers']['order'][$returnStatus])
        ) {
            ksort($this->pluginSettings['finishers']['order'][$returnStatus]);
            foreach ($this->pluginSettings['finishers']['order'][$returnStatus] as $finisherConfig) {
                $finisherClass = $finisherConfig['class'];

                if (class_exists($finisherClass)) {
                    $finisher = $this->objectManager->get($finisherClass);
                    $finisher->execute($finisherContext);
                    if ($finisherContext->isCancelled()) {
                        break;
                    }
                } else {
                    $logManager = $this->objectManager->get(
                        \TYPO3\CMS\Core\Log\LogManager::class
                    );
                    $logger = $logManager->getLogger(__CLASS__);
                    $logger->error('Can\'t find Finisher class \'' . $finisherClass . '\'.', []);
                }
            }
        }
    }

    /**
     * @param string $translationKey
     *
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function addFlashMessageToCartCart(string $translationKey): void
    {
        $flashMessage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Messaging\FlashMessage::class,
            LocalizationUtility::translate(
                $translationKey,
                $this->extensionName
            ),
            '',
            \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR,
            true
        );

        $flashMessageService = $this->objectManager->get(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier('extbase.flashmessages.tx_cart_cart');
        $messageQueue->enqueue($flashMessage);
    }
}
