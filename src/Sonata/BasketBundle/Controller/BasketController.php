<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\BasketBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


use Symfony\Component\Form\Form;
use Symfony\Component\Form\ChoiceField;
use Symfony\Component\Form\CollectionField;
use Symfony\Component\Form\EntityChoiceField;

use Sonata\Component\Form\Transformer\DeliveryMethodTransformer;
use Sonata\Component\Form\Transformer\PaymentMethodTransformer;
use Sonata\Component\Basket\InvalidBasketStateException;

class BasketController extends Controller
{

    /**
     * return the basket form
     *
     * @return Symfony\Component\Form\Form
     */
    public function getBasketForm()
    {

        // always clone the basket, so the one in session is never altered
        $form = new Form('basket', array(
            'data' => clone $this->get('sonata.basket'),
            'validator' => $this->get('validator'),
            'validation_groups' => 'elements'
        ));

        $elements = new Form('basketElements');
        
        foreach ($this->get('sonata.basket')->getBasketElements() as $basketElement) {

            // ask each product repository to populate an empty group field instance
            // so each line can be tweaked depends on the product logic
            $fieldGroup = $this
                ->get('sonata.product.pool')
                ->getRepository($basketElement->getProduct())
                ->generateFieldGroupBasketElement(
                    new Form($basketElement->getPos()), array(
                        'data' => $basketElement
                    )
                );

            $elements->add($fieldGroup);
        }

        $form->add($elements);
        
        return $form;
    }

    public function indexAction($form = null)
    {
        // make sure the session is enabled
        $this->get('session')->start();

        $form = $form ?: $this->getBasketForm();

        // always validate the basket
        if (!$form->isBound())
        {
            // todo : move this somewhere else
            if ($violations = $this->get('validator')->validate($form, $form->getValidationGroups())) {

                foreach ($violations as $violation) {
                    $propertyPath = new \Symfony\Component\Form\PropertyPath($violation->getPropertyPath());
                    $iterator = $propertyPath->getIterator();

                    if ($iterator->current() == 'data') {
                        $type = \Symfony\Component\Form\Form::DATA_ERROR;
                        $iterator->next(); // point at the first data element
                    } else {
                        $type = \Symfony\Component\Form\Form::FIELD_ERROR;
                    }

                    $form->addError(new \Symfony\Component\Form\FieldError($violation->getMessageTemplate(), $violation->getMessageParameters()), $iterator, $type);
                }
            }
        }

        return $this->render('SonataBasketBundle:Basket:index.twig.html', array(
            'basket' => $this->get('sonata.basket'),
            'form'   => $form,
        ));
    }

    public function updateAction()
    {

        $form = $this->getBasketForm();
        $form->bind($this->get('request'));

        if ($form->isValid()) {

            $basket = $form->getData();
            $basket->reset(false); // remove delivery and payment information
            $basket->clean(); // clean the basket

            // update the basket store in session
            $this->get('session')->set('sonata/basket', $basket);

            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        return $this->forward('SonataBasketBundle:Basket:index', array(
           'form' => $form
        ));
    }

    public function addProductAction()
    {
        $request = $this->get('request');

        // start the session
        $this->get('session')->start();

        if ($request->getMethod() != 'POST') {
            
            throw new ForbiddenHttpException('invalid request');
        }

        $params = $request->get('basket');

        // retrieve the product
        $product = $this
            ->get('doctrine.orm.default_entity_manager')
            ->find('Application\Sonata\ProductBundle\Entity\Product', $params['productId']);

        if (!$product) {
            throw new NotFoundHttpException(sprintf('Unable to find the product with id=%d', $params['productId']));
        }

        // retrieve the custom repository for the product type
        $repository = $this->get('sonata.product.pool')->getRepository($product);

        // load and bind the form
        $form = $repository->getAddBasketForm($product, $this->get('validator'));
        $form->bind($request);

        // if the form is valid add the product to the basket
        if ($form->isValid()) {

            $basket = $this->get('sonata.basket');

            if ($basket->hasProduct($product)) {
                $repository->basketMergeProduct($basket,  $product, $form->getData());
            } else {
                $repository->basketAddProduct($basket,  $product, $form->getData());
            }

            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }
        
        // an error occur, forward the request to the view
        return $this->forward('SonataProductBundle:Product:view', array(
            'productId' => $product,
            'slug'       => $product->getSlug(),
        ));
    }

    public function resetAction()
    {
        $this->get('sonata.basket')->reset();
        
        return $this->redirect($this->generateUrl('sonata_basket_index'));
    }

    public function headerPreviewAction()
    {

//        throw new \Exception();
        return $this->render('SonataBasketBundle:Basket:header_preview.twig.html', array(
             'basket' => $this->get('sonata.basket')
        ));
    }

    public function authentificationStepAction()
    {

        // todo : code the connection bit
        $customers = $this
            ->get('doctrine.orm.default_entity_manager')
            ->createQuery('SELECT c FROM Application\Sonata\CustomerBundle\Entity\Customer c')
            ->setMaxResults(1)
            ->execute();

        $this->get('sonata.basket')->setCustomer(count($customers) > 0 ? $customers[0] : null);

        return $this->redirect($this->generateUrl('sonata_basket_delivery'));
    }

    public function getPaymentForm($basket)
    {

        $form = new Form('payment', array(
            'data'      => $basket,
            'validator' => $this->get('validator'),
            'validation_groups' => 'payment'
        ));

        // retrieve addresses
        $addresses = $this
            ->get('doctrine.orm.default_entity_manager')
            ->createQuery('SELECT a FROM Application\Sonata\CustomerBundle\Entity\Address a INDEX BY a.id WHERE a.type = :type AND a.customer = :customer')
            ->setParameters(array(
                'type' => \Application\Sonata\CustomerBundle\Entity\Address::TYPE_BILLING,
                'customer' => $basket->getCustomer()->getId())
            )->execute();

        $form->add(new EntityChoiceField('paymentAddress', array(
            'em'        =>  $this->get('doctrine.orm.default_entity_manager'),
            'class'     => 'Application\Sonata\CustomerBundle\Entity\Address',
            'expanded'  => true,
            'property'  => 'fullAddress',
            'choices' => $addresses
        )));

        // assign default address
        $address = $basket->getPaymentAddress() ?: current($addresses);
        $basket->setPaymentAddress($address);

        // retrieve the default payment methods
        $methods = $this
            ->get('sonata.payment.selector')
            ->getAvailableMethods($basket, $address);

        if ($methods === false) {

            // something went wrong while selecting
            // redirect the user to the basket index (validation)
            throw new InvalidBasketStateException('no payment method available');
        }

        $choices = array();

        foreach ($methods as $method) {
            $choices[$method->getCode()] = $method->getName();
        }

        $form->add(new ChoiceField('paymentMethod', array(
            'expanded' => true,
            'choices' => $choices,
            'value_transformer' => new PaymentMethodTransformer(array(
                'payment_pool' => $this->get('sonata.payment.pool')
            )),
        )));

        return $form;
    }
    
    public function paymentStepAction()
    {
        $basket = clone $this->get('sonata.basket');

        if ($basket->countBasketElements() == 0) {

            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        $customer = $basket->getCustomer();

        if (!$customer) {
            throw new HttpException('Invalid customer');
        }

        try {
            $form = $this->getPaymentForm($basket);
        } catch(InvalidBasketStateException $e) {

            if($this->container->getParameter('kernel.debug')) {
                throw $e;
            }
            
            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        if ($this->get('request')->getMethod() == 'POST') {
            $form->bind($this->get('request'));

            if ($form->isValid()) {

                // update the basket store in session
                $this->get('session')->set('sonata/basket', $form->getData());

                return $this->redirect($this->generateUrl('sonata_basket_final'));
            }
        }

        return $this->render('SonataBasketBundle:Basket:payment_step.twig.html', array(
            'basket' => $basket,
            'form'   => $form,
            'customer'   => $customer,
            'paymentMethods' => $form->get('paymentMethod')->getOption('choices')
        ));
    }

    public function getDeliveryForm($basket)
    {
        $form = new Form('shipping', array(
            'data'      => $basket,
            'validator' => $this->get('validator'),
            'validation_groups' => 'delivery'
        ));

        // retrieve addresses
        $addresses = $this
            ->get('doctrine.orm.default_entity_manager')
            ->createQuery('SELECT a FROM Application\Sonata\CustomerBundle\Entity\Address a INDEX BY a.id WHERE a.type = :type AND a.customer = :customer')
            ->setParameters(array(
                'type' => \Application\Sonata\CustomerBundle\Entity\Address::TYPE_DELIVERY,
                'customer' => $basket->getCustomer()->getId())
            )->execute();

        $form->add(new EntityChoiceField('deliveryAddress', array(
            'em'        =>  $this->get('doctrine.orm.default_entity_manager'),
            'class'     => 'Application\Sonata\CustomerBundle\Entity\Address',
            'expanded'  => true,
            'property'  => 'fullAddress',
            'choices'   => $addresses,
        )));

        $address = $basket->getDeliveryAddress() ?: current($addresses);

        $basket->setDeliveryAddress($address);

        $methods = $this
            ->get('sonata.delivery.selector')
            ->getAvailableMethods($basket, $address);

        if ($methods === false) {

            // something went wrong while selecting
            // redirect the user to the basket index (validation)
            throw new InvalidBasketStateException('no delivery method available');
        }

        $choices = array();

        foreach ($methods as $method) {
            $choices[$method->getCode()] = $method->getName();
        }

        $form->add(new ChoiceField('deliveryMethod', array(
            'expanded' => true,
            'choices' => $choices,
            'value_transformer' => new DeliveryMethodTransformer(array(
                'delivery_pool' => $this->get('sonata.delivery.pool')
            )),
        )));

        return $form;
    }

    public function deliveryStepAction()
    {

        $basket = clone $this->get('sonata.basket');

        if ($basket->countBasketElements() == 0) {

            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        $customer = $basket->getCustomer();

        if (!$customer) {
            throw new NotFoundHttpException('customer not found');
        }

        $form = $this->getDeliveryForm($basket);

        if ($this->get('request')->getMethod() == 'POST') {
            $form->bind($this->get('request'));

            if ($form->isValid()) {

                // update the basket store in session
                $this->get('session')->set('sonata/basket', $form->getData());

                return $this->redirect($this->generateUrl('sonata_basket_payment'));
            }
        }

        return $this->render('SonataBasketBundle:Basket:delivery_step.twig.html', array(
            'basket' => $basket,
            'form'   => $form,
            'customer'   => $customer,
            'deliveryMethods' => $form->get('deliveryMethod')->getOption('choices')
        ));
    }

    public function finalReviewStepAction()
    {

        $basket = $this->get('sonata.basket');

        $violations = $this->get('validator')->validate($basket, array('elements', 'delivery', 'payment'));
        if ($violations->count() > 0) {
            // basket not valid

            // todo : add flash message

            return $this->redirect($this->generateUrl('sonata_basket_index'));
        }

        if ($this->get('request')->getMethod() == 'POST' ) {

            if ($this->get('request')->get('tac')) {
                // send the basket to the payment callback
                return $this->forward('SonataPaymentBundle:Payment:callbank');
            }
        }

        return $this->render('SonataBasketBundle:Basket:final_review_step.twig.html', array(
            'basket'    => $basket,
            'tac_error' => $this->get('request')->getMethod() == 'POST'
        ));
    }
}
