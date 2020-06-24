<?php

namespace Crm\PaymentsModule\Repository;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Repository;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\PaymentItem\PaymentItemInterface;
use League\Event\Emitter;
use Nette\Caching\IStorage;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class PaymentItemsRepository extends Repository
{
    protected $tableName = 'payment_items';

    private $applicationConfig;

    private $emitter;

    public function __construct(
        Context $database,
        ApplicationConfig $applicationConfig,
        Emitter $emitter,
        IStorage $cacheStorage = null
    ) {
        parent::__construct($database, $cacheStorage);
        $this->applicationConfig = $applicationConfig;
        $this->emitter = $emitter;
    }

    final public function add(IRow $payment, PaymentItemContainer $container): array
    {
        $rows = [];
        /** @var PaymentItemInterface $item */
        foreach ($container->items() as $item) {
            $data = [
                'payment_id' => $payment->id,
                'type' => $item->type(),
                'count' => $item->count(),
                'name' => $item->name(),
                'amount' => $item->unitPrice(),
                'amount_without_vat' => round($item->unitPrice() / (1 + ($item->vat()/100)), 2),
                'vat' => $item->vat(),
                'created_at' => new DateTime(),
                'updated_at' => new DateTime(),
            ];
            foreach ($item->data() as $key => $value) {
                $data[$key] = $value;
            }
            $rows[] = $this->insert($data);
        }
        return $rows;
    }

    final public function deleteByPayment(IRow $payment, $type = null)
    {
        $q = $this->getTable()
            ->where('payment_id', $payment->id);
        if ($type) {
            $q->where('type = ?', $type);
        }
        return $q->delete();
    }

    /**
     * @param IRow $payment
     * @param string $paymentItemType
     * @return array|IRow[]
     */
    final public function getByType(IRow $payment, string $paymentItemType): array
    {
        return $payment->related('payment_items')->where('type = ?', $paymentItemType)->fetchAll();
    }

    final public function getTypes(): array
    {
        return $this->getTable()->select('DISTINCT type')->fetchPairs('type', 'type');
    }
}
