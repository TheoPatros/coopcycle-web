<?php

namespace AppBundle\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use AppBundle\Api\Filter\DeliveryOrderFilter;
use AppBundle\Entity\Delivery\Package as DeliveryPackage;
use AppBundle\Entity\Package;
use AppBundle\Entity\Task\CollectionInterface as TaskCollectionInterface;
use AppBundle\ExpressionLanguage\PackagesResolver;
use AppBundle\Validator\Constraints\Delivery as AssertDelivery;
use AppBundle\Validator\Constraints\CheckDelivery as AssertCheckDelivery;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Sylius\Component\Order\Model\OrderInterface;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @see http://schema.org/ParcelDelivery Documentation on Schema.org
 *
 * @ApiResource(iri="http://schema.org/ParcelDelivery",
 *   collectionOperations={
 *     "post"={
 *       "method"="POST",
 *       "denormalization_context"={"groups"={"delivery_create"}}
 *     },
 *     "check"={
 *       "method"="POST",
 *       "path"="/deliveries/assert",
 *       "write"=false,
 *       "status"=200,
 *       "validation_groups"={"Default", "delivery_check"},
 *       "denormalization_context"={"groups"={"delivery_create"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "method"="GET"
 *     },
 *     "put"={
 *        "method"="PUT",
 *        "access_control"="is_granted('ROLE_ADMIN') or (is_granted('ROLE_OAUTH2_DELIVERIES') and oauth2_context.store == object.getStore())"
 *     }
 *   },
 *   attributes={
 *     "order"={"createdAt": "DESC"},
 *     "denormalization_context"={"groups"={"order_create"}},
 *     "normalization_context"={"groups"={"delivery", "place", "order"}},
 *     "pagination_items_per_page"=15
 *   }
 * )
 * @ApiFilter(OrderFilter::class, properties={"createdAt"})
 * @ApiFilter(DeliveryOrderFilter::class, properties={"dropoff.before"})
 * @AssertDelivery
 * @AssertCheckDelivery(groups={"delivery_check"})
 */
class Delivery extends TaskCollection implements TaskCollectionInterface
{
    const VEHICLE_BIKE = 'bike';
    const VEHICLE_CARGO_BIKE = 'cargo_bike';

    /**
     * @Groups({"delivery"})
     */
    protected $id;

    private $order;

    private $weight;

    private $vehicle = self::VEHICLE_BIKE;

    /**
     * @Groups({"delivery_create"})
     */
    private $store;

    private $packages;

    public function __construct()
    {
        parent::__construct();

        $pickup = new Task();
        $pickup->setType(Task::TYPE_PICKUP);
        $pickup->setDelivery($this);

        $dropoff = new Task();
        $dropoff->setType(Task::TYPE_DROPOFF);
        $dropoff->setDelivery($this);

        $pickup->setNext($dropoff);
        $dropoff->setPrevious($pickup);

        $this->addTask($pickup);
        $this->addTask($dropoff);

        $this->packages = new ArrayCollection();
    }

    public function addTask(Task $task, $position = null)
    {
        $pickup = $this->getPickup();
        $dropoff = $this->getDropoff();

        if (null === $pickup && $task->isPickup()) {
            parent::addTask($task, $position);
            return;
        }

        if (null === $dropoff && $task->isDropoff()) {
            parent::addTask($task, $position);
            return;
        }

        throw new \RuntimeException('No additional task can be added');
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function setOrder(OrderInterface $order)
    {
        $this->order = $order;

        return $this;
    }

    public function getWeight()
    {
        return $this->weight;
    }

    public function setWeight($weight)
    {
        $this->weight = $weight;

        return $this;
    }

    public function getVehicle()
    {
        return $this->vehicle;
    }

    public function setVehicle($vehicle)
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    /**
     * @Groups({"delivery"})
     */
    public function getPickup()
    {
        foreach ($this->getTasks() as $task) {
            if ($task->getType() === Task::TYPE_PICKUP) {
                return $task;
            }
        }
    }

    /**
     * @Groups({"delivery"})
     */
    public function getDropoff()
    {
        foreach ($this->getTasks() as $task) {
            if ($task->getType() === Task::TYPE_DROPOFF) {
                return $task;
            }
        }
    }

    public static function create()
    {
        return new self();
    }

    public static function createWithAddress($pickupAddress, $dropoffAddress)
    {
        $delivery = self::createWithDefaults();

        $delivery->getPickup()->setAddress($pickupAddress);
        $delivery->getDropoff()->setAddress($dropoffAddress);

        return $delivery;
    }

    public static function createWithDefaults()
    {
        $pickupDoneBefore = new \DateTime();
        $pickupDoneBefore->modify('+1 day');

        $dropoffDoneBefore = clone $pickupDoneBefore;
        $dropoffDoneBefore->modify('+1 hour');

        $delivery = self::create();

        $delivery->getPickup()->setDoneBefore($pickupDoneBefore);
        $delivery->getDropoff()->setDoneBefore($dropoffDoneBefore);

        return $delivery;
    }

    public function setStore(Store $store)
    {
        $this->store = $store;
    }

    public function getStore()
    {
        return $this->store;
    }

    public function isAssigned()
    {
        return $this->getPickup()->isAssigned() && $this->getDropoff()->isAssigned();
    }

    public function isCompleted()
    {
        foreach ($this->getTasks() as $task) {
            if (!$task->isCompleted()) {

                return false;
            }
        }

        return true;
    }

    public function setPackages($packages)
    {
        $this->packages = $packages;
    }

    public function getPackages()
    {
        return $this->packages;
    }

    public function hasPackages()
    {
        return count($this->packages) > 0;
    }

    public function addPackageWithQuantity(Package $package, $quantity = 1)
    {
        if (0 === $quantity) {
            return;
        }

        $deliveryPackage = $this->resolvePackage($package);
        $deliveryPackage->setQuantity($deliveryPackage->getQuantity() + $quantity);

        if (!$this->packages->contains($deliveryPackage)) {
            $this->packages->add($deliveryPackage);
        }
    }

    private function resolvePackage(Package $package): DeliveryPackage
    {
        if ($this->hasPackage($package)) {
            foreach ($this->packages as $deliveryPackage) {
                if ($deliveryPackage->getPackage() === $package) {
                    return $deliveryPackage;
                }
            }
        }

        $deliveryPackage = new DeliveryPackage($this);
        $deliveryPackage->setPackage($package);

        return $deliveryPackage;
    }

    public function hasPackage(Package $package)
    {
        foreach ($this->packages as $p) {
            if ($p->getPackage() === $package) {
                return true;
            }
        }

        return false;
    }

    public function getQuantityForPackage(Package $package)
    {
        foreach ($this->packages as $p) {
            if ($p->getPackage() === $package) {
                return $p->getQuantity();
            }
        }

        return 0;
    }

    private static function createTaskObject(?Task $task)
    {
        $taskObject = new \stdClass();
        if ($task) {
            $taskObject->address = $task->getAddress();
            $taskObject->createdAt = $task->getCreatedAt();
            $taskObject->before = $task->getDoneBefore();
        }

        return $taskObject;
    }

    public static function toExpressionLanguageValues(Delivery $delivery)
    {
        $pickup = self::createTaskObject($delivery->getPickup());
        $dropoff = self::createTaskObject($delivery->getDropoff());

        return [
            'distance' => $delivery->getDistance(),
            'weight' => $delivery->getWeight(),
            'vehicle' => $delivery->getVehicle(),
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'packages' => new PackagesResolver($delivery),
        ];
    }

    public function setPickupRange(\DateTime $after, \DateTime $before)
    {
        $this->getPickup()
            ->setDoneAfter($after)
            ->setDoneBefore($before);

        return $this;
    }

    public function setDropoffRange(\DateTime $after, \DateTime $before)
    {
        $this->getDropoff()
            ->setDoneAfter($after)
            ->setDoneBefore($before);

        return $this;
    }
}
