<?php

namespace Crm\PaymentsModule\MailConfirmation;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Repository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\VariableSymbolVariant;
use Nette\Caching\IStorage;
use Nette\Database\Context;

class ParsedMailLogsRepository extends Repository
{
    const STATE_WITHOUT_VS = 'without_vs';
    const STATE_ALREADY_PAID = 'already_paid';
    const STATE_CHANGED_TO_PAID = 'changed_to_paid';
    const STATE_PAYMENT_NOT_FOUND = 'payment_not_found';
    const STATE_DIFFERENT_AMOUNT = 'different_amount';
    const STATE_AUTO_NEW_PAYMENT = 'auto_new_payment';
    const STATE_NO_SIGN = 'no_sign';
    const STATE_NOT_VALID_SIGN = 'no_valid_sign';

    protected $tableName = 'parsed_mail_logs';

    private $paymentsRepository;

    private $cacheRepository;

    public function __construct(
        Context $database,
        IStorage $cacheStorage = null,
        PaymentsRepository $paymentsRepository,
        CacheRepository $cacheRepository
    ) {
        parent::__construct($database, $cacheStorage);
        $this->paymentsRepository = $paymentsRepository;
        $this->cacheRepository = $cacheRepository;
    }

    public function all($vs = '', $state = '')
    {
        $where = [];
        if ($vs) {
            $where['variable_symbol LIKE ?'] = "%{$vs}%";
        }
        if ($state) {
            $where['state'] = $state;
        }
        return $this->getTable()->where($where)->order('delivered_at DESC');
    }

    public function findByVariableSymbols($variableSymbols)
    {
        $variableSymbolVariants = new VariableSymbolVariant();
        $variableSymbols = $variableSymbolVariants->variableSymbolsVariants($variableSymbols);
        return $this->getTable()->where(['variable_symbol' => $variableSymbols])->order('delivered_at DESC');
    }

    public function lastLog()
    {
        return $this->getTable()->order('created_at DESC')->limit(1)->fetch();
    }

    /**
     * Cached form payments with wrong amount
     * @param bool $forceCacheUpdate
     *
     * @return array
     */
    public function formPaymentsWithWrongAmount($forceCacheUpdate = false)
    {
        $callable = function () {
            $wrongAmountPayments = $this->all('', 'different_amount');

            $listPayments = [];
            foreach ($wrongAmountPayments as $wrongAmountPayment) {
                $payment = $this->paymentsRepository->findLastByVS($wrongAmountPayment->variable_symbol);
                if ($payment && $payment->status == PaymentsRepository::STATUS_FORM) {
                    $listPayments[] = [
                        'user_id' => $payment->user->id,
                        'amount' => $wrongAmountPayment->amount,
                        'email' => $payment->user->email
                    ];
                }
            }
            return json_encode($listPayments);
        };

        return json_decode($this->cacheRepository->loadByKeyAndUpdate(
            'payments_paid_sum',
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::LONGER_REFRESH_TIME),
            $forceCacheUpdate
        ), true);
    }
}
