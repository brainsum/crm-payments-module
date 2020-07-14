<?php

namespace Crm\PaymentsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\VisualPaginator;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\PaymentsModule\Forms\RetentionAnalysisFilterFormFactory;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RetentionAnalysisJobsRepository;
use Crm\PaymentsModule\Retention\RetentionAnalysis;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Nette\Utils\Json;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;
use Tomaj\Hermes\Emitter;

class RetentionAnalysisAdminPresenter extends AdminPresenter
{
    /** @var DataProviderManager @inject */
    public $dataProviderManager;

    /** @var SubscriptionTypesRepository @inject */
    public $subscriptionTypesRepository;

    /** @var PaymentsRepository @inject */
    public $paymentsRepository;

    /** @var SubscriptionsRepository @inject */
    public $subscriptionsRepository;

    /** @var RetentionAnalysisJobsRepository @inject */
    public $retentionAnalysisJobsRepository;

    /** @var RetentionAnalysis @inject */
    public $retentionAnalysis;

    /** @var RetentionAnalysisFilterFormFactory @inject */
    public $retentionAnalysisFilterFormFactory;

    /** @var Emitter @inject */
    public $hermesEmitter;

    public function renderDefault()
    {
        $jobs = $this->retentionAnalysisJobsRepository->all();

        $vp = new VisualPaginator();
        $this->addComponent($vp, 'vp');
        $paginator = $vp->getPaginator();
        $paginator->setItemCount($jobs->count('*'));
        $paginator->setItemsPerPage($this->onPage);
        $this->template->vp = $vp;
        $this->template->jobs = $jobs->limit(
            $paginator->getLength(),
            $paginator->getOffset()
        );
    }

    public function renderNew()
    {
        if ($this->getParameter('submitted')) {
            $this->template->paymentCounts = $this->retentionAnalysis->precalculateMonthlyPaymentCounts($this->params);
        }
    }

    public function renderShow($job)
    {
        $job = $this->retentionAnalysisJobsRepository->find($job);
        if (!$job) {
            throw new BadRequestException();
        }
        $this->template->job = $job;

        if ($job->results) {
            $results = Json::decode($job->results, Json::FORCE_ARRAY);
            ksort($results);
            $colsCount = count($results[array_key_first($results)]) ?? 0;

            $tableRows = [];

            $allPeriodCounts = 0;
            $maxPeriodCount = 0;
            $periodNumberCounts = [];

            foreach ($results as $yearMonth => $result) {
                $tableRow = [];
                [$tableRow['year'], $tableRow['month']] =  explode('-', $yearMonth);
                $tableRow['fullPeriodCount'] = $fullPeriodCount = $result[array_key_first($result)];
                $allPeriodCounts += $fullPeriodCount;
                $maxPeriodCount = max($maxPeriodCount, $fullPeriodCount);
                $tableRow['periods'] = [];

                foreach ($result as $period => $periodCount) {
                    $periodNumberCounts[$period] = ($periodNumberCounts[$period] ?? 0) + $periodCount;
                    $ratio = (float) $periodCount/$fullPeriodCount;
                    $tableRow['periods'][] =  [
                        'color' => 'churn-color-' . floor($ratio * 10) * 10,
                        'percentage' =>  number_format($ratio * 100, 1, '.', '') . '%'
                    ];
                }
                $tableRows[] = $tableRow;
            }

            $this->template->periodNumberCounts = [];
            foreach ($periodNumberCounts as $periodNumberCount) {
                $ratio = (float) $periodNumberCount / $allPeriodCounts;
                $this->template->periodNumberCounts[] = [
                    'color' => 'churn-color-' . floor($ratio * 10) * 10,
                    'value' =>  $periodNumberCount,
                ];
            }

            foreach ($tableRows as $i => $tableRow) {
                $ratio = $tableRow['fullPeriodCount'] / $maxPeriodCount;
                $tableRow['fullPeriodCount'] = [
                    'value' => $tableRow['fullPeriodCount'],
                    'color' => 'churn-color-' . floor($ratio * 10) * 10,
                ];
                $tableRows[$i] = $tableRow;
            }

            $this->template->allPeriodCounts = $allPeriodCounts;
            $this->template->colsCount = $colsCount;
            $this->template->tableRows = $tableRows;
        }
    }

    public function createComponentFilterForm(): Form
    {
        $form = $this->retentionAnalysisFilterFormFactory->create($this->params);
        $form->onSuccess[] = [$this, 'filterSubmitted'];
        return $form;
    }

    public function createComponentDisabledFilterForm(): Form
    {
        $job = $this->retentionAnalysisJobsRepository->find($this->params['job']);
        $inputParams = Json::decode($job->params, Json::FORCE_ARRAY);
        return $this->retentionAnalysisFilterFormFactory->create($inputParams, true);
    }

    public function filterSubmitted($form, $values)
    {
        $this->redirect('new', (array) $values);
    }

    public function createComponentScheduleComputationForm(): Form
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapInlineRenderer());

        $form->addText('name', 'payments.admin.retention_analysis.analysis_name');
        $form->addHidden('jsonParams', Json::encode($this->params));

        $form->addSubmit('send', 'payments.admin.retention_analysis.schedule_computation')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml($this->translator->translate('payments.admin.retention_analysis.schedule_computation'));

        $form->onSuccess[] = [$this, 'scheduleComputationSubmitted'];
        return $form;
    }

    public function scheduleComputationSubmitted($form, $values)
    {
        $params = array_filter(Json::decode($values['jsonParams'], Json::FORCE_ARRAY));
        unset($params['action'], $params['submitted']);
        $job = $this->retentionAnalysisJobsRepository->add($values['name'], Json::encode($params));
        $this->hermesEmitter->emit(new HermesMessage('retention-analysis-job', [
            'id' => $job->id
        ]));

        $this->flashMessage($this->translator->translate('payments.admin.retention_analysis.analysis_was_scheduled'));
        $this->redirect('default');
    }
}