<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PaymentBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sonata\Component\Payment\TransactionInterface;
use Sonata\Component\Payment\PaymentInterface;
use Sonata\Component\Order\OrderInterface;

class PaymentController extends Controller
{
    public function errorAction()
    {
        // retrieve the payment handler
        $payment    = $this->getPaymentHandler();

        // retrieve the transaction
        $transaction = $this->createTransaction($payment);

        // retrieve the related order
        $reference  = $payment->getOrderReference($transaction);

        $order      = $this->getOrderManager()->findOneby(array(
            'reference' => $reference
        ));

        if (!$order) {
            throw new NotFoundHttpException(sprintf('Order %s', $reference));
        }

        $transaction->setOrder($order);

        // control the handshake value
        if (!$payment->isRequestValid($transaction)) {
            throw new NotFoundHttpException(sprintf('Invalid check - Order %s', $reference));
        }

        // ask the payment handler the error
        $response = $payment->handleError($transaction);

        // save the payment transaction
        $this->getOrderManager()->save($transaction);

        // todo : should I close the order at this point ?
        //        or this logic should be handle by the payment method

        // reset the basket and rebuilt from the order information
        $basket = $this->get('sonata.basket');

        $customer = $basket->getCustomer();

        $basket   = $payment->getTransformer('order')->transformIntoBasket($customer, $order, $basket);

        $this->get('session')->set('sonata/basket', $basket);

        return $this->render('PaymentBundle:Payment:error.html.twig', array(
            'order' => $order,
            'basket' => $basket
        ));
    }

    public function confirmationAction()
    {
        $request    = $this->get('request');
        $bank       = $request->get('bank');
        $payment    = $this->get(sprintf('sonata.payment.method.%s', $bank));
        $transaction = $this->get('sonata.transaction.manager')->create();

        // build the transaction
        $transaction->setPaymentCode($bank);
        $transaction->setParameters(array_replace($request->query->all(), $request->request->all()));

        $reference = $payment->getOrderReference($transaction);

        $em = $this->get('doctrine.orm.entity_manager');
        $order = $em->getRepository('OrderBundle:Order')->findOneByReference($reference);

        if (!$order) {
            throw new NotFoundHttpException(sprintf('Order %s', $reference));
        }

        return $this->render('PaymentBundle:Payment:confirmation.html.twig', array(
            'order' => $order,
        ));
    }

    /**
     *
     * this action redirect the user to the bank
     *
     * @return Response
     */
    public function callbankAction()
    {
        $basket     = $this->get('sonata.basket');
        $request    = $this->get('request');

        if ($request->getMethod() !== 'POST') {
            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        if (!$basket->isValid()) {
            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        $payment = $basket->getPaymentMethod();

        // check if the basket is valid/compatible with the bank gateway
        if (!$payment->isBasketValid($basket)) {
            $this->get('session')->setFlash(
                'notice',
                $this->container->get('translator')->trans('sonata.payment.basket_not_valid_with_current_payment_method', array(), 'SonataPaymentBundle')
            );

            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        // transform the basket into order
        $order = $payment->getTransformer('basket')->transformIntoOrder($basket);

        // save the order
        $this->get('sonata.order.manager')->save($order);

        // assign correct reference number
        $this->get('sonata.generator')->order($order);

        $basket->reset();

        // the payment must handle everything when calling the bank
        return $payment->callbank($order);
    }

    /**
     * this action handler the callback sent from the bank
     *
     * @return Response
     */
    public function callbackAction()
    {
        // retrieve the payment handler
        $payment = $this->getPaymentHandler();

        // build the transaction
        $transaction = $this->createTransaction($payment);

        // retrieve the related order
        $reference  = $payment->getOrderReference($transaction);

        $order = $this->getOrderManager()->findOneBy(array(
            'reference' => $reference
        ));

        if (!$order instanceof OrderInterface) {
            throw new NotFoundHttpException(sprintf('Unable to find the Order %s', $reference));
        }

        $transaction->setOrder($order);

        if (!$payment->isCallbackValid($transaction)) {
            // ask the payment handler the error
            return $payment->handleError($transaction);
        }

        $response = $payment->sendConfirmationReceipt($transaction);

        $this->getTransactionManager()->save($transaction);
        $this->getOrderManager()->save($transaction->getOrder());

        return $response;
    }

    /**
     * @param \Sonata\Component\Payment\PaymentInterface $payment
     * @return \Sonata\Component\Payment\TransactionInterface
     */
    public function createTransaction(PaymentInterface $payment)
    {
        $transaction = $this->get('sonata.transaction.manager')->create();
        $transaction->setPaymentCode($payment->getCode());
        $transaction->setCreatedAt(new \DateTime);
        $transaction->setParameters(array_replace($this->getRequest()->query->all(), $this->getRequest()->request->all()));

        $payment->applyTransactionId($transaction);

        return $transaction;
    }

    /**
     * @return object|\Sonata\Component\Payment\PaymentInterface
     */
    public function getPaymentHandler()
    {
        $payment = $this->get(sprintf('sonata.payment.method.%s', $this->getRequest()->get('bank')));

        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException();
        }

        return $payment;
    }

    /**
     * @return object|\Sonata\Component\Order\OrderManagerInterface
     */
    public function getOrderManager()
    {
        return $this->get('sonata.order.manager');
    }

    /**
     * @return object|\Sonata\Component\Payment\TransactionManagerInterface
     */
    public function getTransactionManager()
    {
        return $this->get('sonata.transaction.manager');
    }
}