<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Model;

use Magento\Framework\Api\ExtensionAttribute\JoinProcessorInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sales\Api\Data\OrderExtensionInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterfaceFactory as SearchResultFactory;
use Magento\Sales\Model\Order\ShippingAssignmentBuilder;
use Magento\Sales\Model\ResourceModel\Metadata;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Magento\Payment\Api\Data\PaymentAdditionalInfoInterface;
use Magento\Payment\Api\Data\PaymentAdditionalInfoInterfaceFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

/**
 * Repository class
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * phpcs:disable Magento2.Annotation.MethodAnnotationStructure
 */
class OrderRepository implements \Magento\Sales\Api\OrderRepositoryInterface, ResetAfterRequestInterface
{
    /**
     * @var Metadata
     */
    protected $metadata;

    /**
     * @var SearchResultFactory
     */
    protected $searchResultFactory = null;

    /**
     * @var ShippingAssignmentBuilder
     */
    private $shippingAssignmentBuilder;

    /**
     * @var CollectionProcessorInterface
     */
    private $collectionProcessor;

    /**
     * @var OrderInterface[]
     */
    protected $registry = [];

    /**
     * @var OrderTaxManagementInterface
     */
    private $orderTaxManagement;

    /**
     * @var PaymentAdditionalInfoFactory
     */
    private $paymentAdditionalInfoFactory;

    /**
     * @var JsonSerializer
     */
    private $serializer;

    /**
     * @var JoinProcessorInterface
     */
    private $extensionAttributesJoinProcessor;

    /**
     * Constructor
     *
     * @param Metadata $metadata
     * @param SearchResultFactory $searchResultFactory
     * @param CollectionProcessorInterface|null $collectionProcessor
     * @param OrderTaxManagementInterface|null $orderTaxManagement
     * @param PaymentAdditionalInfoInterfaceFactory|null $paymentAdditionalInfoFactory
     * @param JsonSerializer|null $serializer
     * @param JoinProcessorInterface|null $extensionAttributesJoinProcessor
     * @param ShippingAssignmentBuilder|null $shippingAssignmentBuilder
     */
    public function __construct(
        Metadata $metadata,
        SearchResultFactory $searchResultFactory,
        ?CollectionProcessorInterface $collectionProcessor = null,
        ?OrderTaxManagementInterface $orderTaxManagement = null,
        ?PaymentAdditionalInfoInterfaceFactory $paymentAdditionalInfoFactory = null,
        ?JsonSerializer $serializer = null,
        ?JoinProcessorInterface $extensionAttributesJoinProcessor = null,
        ?ShippingAssignmentBuilder $shippingAssignmentBuilder = null
    ) {
        $this->metadata = $metadata;
        $this->searchResultFactory = $searchResultFactory;
        $this->collectionProcessor = $collectionProcessor ?: ObjectManager::getInstance()
            ->get(\Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface::class);
        $this->orderTaxManagement = $orderTaxManagement ?: ObjectManager::getInstance()
            ->get(OrderTaxManagementInterface::class);
        $this->paymentAdditionalInfoFactory = $paymentAdditionalInfoFactory ?: ObjectManager::getInstance()
            ->get(PaymentAdditionalInfoInterfaceFactory::class);
        $this->serializer = $serializer ?: ObjectManager::getInstance()
            ->get(JsonSerializer::class);
        $this->extensionAttributesJoinProcessor = $extensionAttributesJoinProcessor
            ?: ObjectManager::getInstance()->get(JoinProcessorInterface::class);
        $this->shippingAssignmentBuilder = $shippingAssignmentBuilder
            ?: ObjectManager::getInstance()->get(ShippingAssignmentBuilder::class);
    }

    /**
     * Load entity
     *
     * @param int $id
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get($id)
    {
        if (!$id) {
            throw new InputException(__('An ID is needed. Set the ID and try again.'));
        }
        if (!isset($this->registry[$id])) {
            /** @var OrderInterface $entity */
            $entity = $this->metadata->getNewInstance()->load($id);
            if (!$entity->getEntityId()) {
                throw new NoSuchEntityException(
                    __("The entity that was requested doesn't exist. Verify the entity and try again.")
                );
            }
            $this->setOrderTaxDetails($entity);
            $this->setShippingAssignments($entity);
            $this->setPaymentAdditionalInfo($entity);
            $this->registry[$id] = $entity;
        }
        return $this->registry[$id];
    }

    /**
     * Set order tax details to extension attributes.
     *
     * @param OrderInterface $order
     * @return void
     * @throws NoSuchEntityException
     */
    private function setOrderTaxDetails(OrderInterface $order)
    {
        $extensionAttributes = $order->getExtensionAttributes();

        $orderTaxDetails = $this->orderTaxManagement->getOrderTaxDetails($order->getEntityId());
        $appliedTaxes = $orderTaxDetails->getAppliedTaxes();

        $extensionAttributes->setAppliedTaxes($appliedTaxes);
        $extensionAttributes->setConvertingFromQuote(false);

        $items = $orderTaxDetails->getItems();
        $extensionAttributes->setItemAppliedTaxes($items);

        $order->setExtensionAttributes($extensionAttributes);
    }

    /**
     * Set additional info to the order.
     *
     * @param OrderInterface $order
     * @return void
     */
    private function setPaymentAdditionalInfo(OrderInterface $order): void
    {
        $extensionAttributes = $order->getExtensionAttributes();

        $paymentAdditionalInformation = [];
        $payment = $order->getPayment();

        if ($payment) {
            $paymentAdditionalInformation = $payment->getAdditionalInformation();
        }

        $objects = [];
        foreach ($paymentAdditionalInformation as $key => $value) {
            /** @var PaymentAdditionalInfoInterface $additionalInformationObject */
            $additionalInformationObject = $this->paymentAdditionalInfoFactory->create();
            $additionalInformationObject->setKey($key);

            if (!is_string($value)) {
                $value = $this->serializer->serialize($value);
            }
            $additionalInformationObject->setValue($value);

            $objects[] = $additionalInformationObject;
        }
        $extensionAttributes->setPaymentAdditionalInfo($objects);
        $order->setExtensionAttributes($extensionAttributes);
    }

    /**
     * Find entities by criteria
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Sales\Api\Data\OrderSearchResultInterface
     * @throws NoSuchEntityException
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria)
    {
        $searchResult = $this->searchResultFactory->create();
        $this->extensionAttributesJoinProcessor->process($searchResult);
        $this->collectionProcessor->process($searchCriteria, $searchResult);
        $searchResult->setSearchCriteria($searchCriteria);
        foreach ($searchResult->getItems() as $order) {
            $this->setShippingAssignments($order);
            $this->setOrderTaxDetails($order);
            $this->setPaymentAdditionalInfo($order);
        }
        return $searchResult;
    }

    /**
     * Register entity to delete
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $entity
     * @return bool
     */
    public function delete(\Magento\Sales\Api\Data\OrderInterface $entity)
    {
        $this->metadata->getMapper()->delete($entity);
        unset($this->registry[$entity->getEntityId()]);
        return true;
    }

    /**
     * Delete entity by Id
     *
     * @param int $id
     * @return bool
     */
    public function deleteById($id)
    {
        $entity = $this->get($id);
        return $this->delete($entity);
    }

    /**
     * Perform persist operations for one entity
     *
     * @param OrderInterface $entity
     * @return OrderInterface
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function save(\Magento\Sales\Api\Data\OrderInterface $entity)
    {
        /** @var  \Magento\Sales\Api\Data\OrderExtensionInterface $extensionAttributes */
        $extensionAttributes = $entity->getExtensionAttributes();
        if ($entity->getIsNotVirtual() && $extensionAttributes) {
            $shippingAssignments = $extensionAttributes->getShippingAssignments();
            if (!empty($shippingAssignments)) {
                $shipping = array_shift($shippingAssignments)->getShipping();
                $shippingAddress = $shipping->getAddress();
                $shippingEmail = ($shippingAddress !== null) ? $shippingAddress->getEmail() : null;
                $shippingMethod = $shipping->getMethod();
                $entity->setShippingAddress($shippingAddress);
                $entity->setShippingMethod($shippingMethod);
                if (!$entity->getCustomerEmail() && $shippingEmail) {
                    $entity->setCustomerEmail($shippingEmail);
                }
            }
        }

        $this->metadata->getMapper()->save($entity);
        $this->registry[$entity->getEntityId()] = $entity;
        return $this->registry[$entity->getEntityId()];
    }

    /**
     * Set shipping assignments to extension attributes.
     *
     * @param OrderInterface $order
     * @return void
     */
    private function setShippingAssignments(OrderInterface $order)
    {
        /** @var OrderExtensionInterface $extensionAttributes */
        $extensionAttributes = $order->getExtensionAttributes();

        if ($extensionAttributes->getShippingAssignments() !== null) {
            return;
        }
        $this->shippingAssignmentBuilder->setOrder($order);
        $extensionAttributes->setShippingAssignments($this->shippingAssignmentBuilder->create());
        $order->setExtensionAttributes($extensionAttributes);
    }

    /**
     * Helper function that adds a FilterGroup to the collection.
     *
     * @param \Magento\Framework\Api\Search\FilterGroup $filterGroup
     * @param \Magento\Sales\Api\Data\OrderSearchResultInterface $searchResult
     * @return void
     * @deprecated 101.0.0
     * @throws \Magento\Framework\Exception\InputException
     */
    protected function addFilterGroupToCollection(
        \Magento\Framework\Api\Search\FilterGroup $filterGroup,
        \Magento\Sales\Api\Data\OrderSearchResultInterface $searchResult
    ) {
        $fields = [];
        $conditions = [];
        foreach ($filterGroup->getFilters() as $filter) {
            $condition = $filter->getConditionType() ? $filter->getConditionType() : 'eq';
            $conditions[] = [$condition => $filter->getValue()];
            $fields[] = $filter->getField();
        }
        if ($fields) {
            $searchResult->addFieldToFilter($fields, $conditions);
        }
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->registry = [];
    }
}
