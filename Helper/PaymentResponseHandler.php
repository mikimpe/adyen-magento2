<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2023 Adyen N.V. (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Helper;

use Adyen\Payment\Logger\AdyenLogger;
use Exception;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order;

class PaymentResponseHandler
{
    const AUTHORISED = 'Authorised';
    const REFUSED = 'Refused';
    const REDIRECT_SHOPPER = 'RedirectShopper';
    const IDENTIFY_SHOPPER = 'IdentifyShopper';
    const CHALLENGE_SHOPPER = 'ChallengeShopper';
    const RECEIVED = 'Received';
    const PENDING = 'Pending';
    const PRESENT_TO_SHOPPER = 'PresentToShopper';
    const ERROR = 'Error';
    const CANCELLED = 'Cancelled';
    const ADYEN_TOKENIZATION = 'Adyen Tokenization';
    const VAULT = 'Magento Vault';
    const POS_SUCCESS = 'Success';

    /**
     * @var AdyenLogger
     */
    private AdyenLogger $adyenLogger;

    /**
     * @var Vault
     */
    private Vault $vaultHelper;

    /**
     * @var Order
     */
    private Order $orderResourceModel;

    /**
     * @var Data
     */
    private Data $dataHelper;

    /**
     * @var Quote
     */
    private Quote $quoteHelper;

    /**
     * @param AdyenLogger $adyenLogger
     * @param Vault $vaultHelper
     * @param Order $orderResourceModel
     * @param Data $dataHelper
     * @param Quote $quoteHelper
     */
    public function __construct(
        AdyenLogger $adyenLogger,
        Vault $vaultHelper,
        Order $orderResourceModel,
        Data $dataHelper,
        Quote $quoteHelper
    ) {
        $this->adyenLogger = $adyenLogger;
        $this->vaultHelper = $vaultHelper;
        $this->orderResourceModel = $orderResourceModel;
        $this->dataHelper = $dataHelper;
        $this->quoteHelper = $quoteHelper;
    }

    public function formatPaymentResponse(
        string $resultCode,
        array $action = null,
        array $additionalData = null
    ): array {
        switch ($resultCode) {
            case self::AUTHORISED:
            case self::REFUSED:
            case self::ERROR:
            case self::POS_SUCCESS:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode
                ];
            case self::REDIRECT_SHOPPER:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
            case self::PENDING:
                return [
                    "isFinal" => false,
                    "resultCode" => $resultCode,
                    "action" => $action
                ];
            case self::PRESENT_TO_SHOPPER:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "action" => $action
                ];
            case self::RECEIVED:
                return [
                    "isFinal" => true,
                    "resultCode" => $resultCode,
                    "additionalData" => $additionalData
                ];
            default:
                return [
                    "isFinal" => true,
                    "resultCode" => self::ERROR,
                ];
        }
    }

    /**
     * @param array $paymentsResponse
     * @param Payment $payment
     * @param OrderInterface|null $order
     * @return bool
     * @throws LocalizedException
     * @throws AlreadyExistsException
     */
    public function handlePaymentResponse(
        array $paymentsResponse,
        Payment $payment,
        OrderInterface $order = null
    ):bool {
        if (empty($paymentsResponse)) {
            $this->adyenLogger->error("Payment details call failed, paymentsResponse is empty");
            return false;
        }

        if (!empty($paymentsResponse['resultCode'])) {
            $payment->setAdditionalInformation('resultCode', $paymentsResponse['resultCode']);
        }

        if (!empty($paymentsResponse['action'])) {
            $payment->setAdditionalInformation('action', $paymentsResponse['action']);
        }

        if (!empty($paymentsResponse['additionalData'])) {
            $payment->setAdditionalInformation('additionalData', $paymentsResponse['additionalData']);
        }

        if (!empty($paymentsResponse['pspReference'])) {
            $payment->setAdditionalInformation('pspReference', $paymentsResponse['pspReference']);
        }

        if (!empty($paymentsResponse['details'])) {
            $payment->setAdditionalInformation('details', $paymentsResponse['details']);
        }

        switch ($paymentsResponse['resultCode']) {
            case self::PRESENT_TO_SHOPPER:
            case self::PENDING:
            case self::RECEIVED:
            case self::IDENTIFY_SHOPPER:
            case self::CHALLENGE_SHOPPER:
                break;
            //We don't need to handle these resultCodes
            case self::REDIRECT_SHOPPER:
                $this->adyenLogger->addAdyenResult("Customer was redirected.");
                if ($order) {
                    $order->addStatusHistoryComment(
                        __(
                            'Customer was redirected to an external payment page. (In case of card payments the shopper is redirected to the bank for 3D-secure validation.) Once the shopper is authenticated,
                        the order status will be updated accordingly.
                        <br />Make sure that your notifications are being processed!
                        <br />If the order is stuck on this status, the shopper abandoned the session.
                        The payment can be seen as unsuccessful.
                        <br />The order can be automatically cancelled based on the OFFER_CLOSED notification.
                        Please contact Adyen Support to enable this.'
                        ),
                        $order->getStatus()
                    )->save();
                }
                break;
            case self::AUTHORISED:
                if (!empty($paymentsResponse['pspReference'])) {
                    // set pspReference as transactionId
                    $payment->setCcTransId($paymentsResponse['pspReference']);
                    $payment->setLastTransId($paymentsResponse['pspReference']);

                    // set transaction
                    $payment->setTransactionId($paymentsResponse['pspReference']);
                }

                // Handle recurring details
                $this->vaultHelper->handlePaymentResponseRecurringDetails($payment, $paymentsResponse);

                if (!empty($paymentsResponse['donationToken'])) {
                    $payment->setAdditionalInformation('donationToken', $paymentsResponse['donationToken']);
                }

                $this->orderResourceModel->save($order);
                try {
                    $this->quoteHelper->disableQuote($order->getQuoteId());
                } catch (Exception $e) {
                    $this->adyenLogger->error('Failed to disable quote: ' . $e->getMessage(), [
                        'quoteId' => $order->getQuoteId()
                    ]);
                }
                break;
            case self::REFUSED:
                // Cancel order in case result is refused
                if (null !== $order) {
                    // Set order to new so it can be cancelled
                    $order->setState(\Magento\Sales\Model\Order::STATE_NEW);
                    $order->save();
                    $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
                    $this->dataHelper->cancelOrder($order);
                }
                return false;
            case self::ERROR:
            default:
                $this->adyenLogger->error(
                    sprintf("Payment details call failed for action, resultCode is %s Raw API responds: %s",
                        $paymentsResponse['resultCode'],
                        json_encode($paymentsResponse)
                    ));

                return false;
        }
        return true;
    }
}
