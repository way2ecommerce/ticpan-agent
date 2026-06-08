<?php

namespace W2e\Ticpan\Controller\Trigger;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use W2e\Ticpan\Helper\Config;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const JOB_CODE = 'ticpan_agent_heartbeat';

    private RequestInterface        $request;
    private JsonFactory             $jsonFactory;
    private Config                  $config;
    private ScheduleFactory         $scheduleFactory;
    private ScheduleCollectionFactory $scheduleCollectionFactory;
    private DateTime                $dateTime;

    public function __construct(
        RequestInterface          $request,
        JsonFactory               $jsonFactory,
        Config                    $config,
        ScheduleFactory           $scheduleFactory,
        ScheduleCollectionFactory $scheduleCollectionFactory,
        DateTime                  $dateTime
    ) {
        $this->request                   = $request;
        $this->jsonFactory               = $jsonFactory;
        $this->config                    = $config;
        $this->scheduleFactory           = $scheduleFactory;
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
        $this->dateTime                  = $dateTime;
    }

    public function execute()
    {
        $result    = $this->jsonFactory->create();
        $timestamp = (int) $this->request->getHeader('X-Ticpan-Timestamp');
        $signature = (string) $this->request->getHeader('X-Ticpan-Signature');
        $body      = (string) $this->request->getContent();

        if (! $this->config->isConfigured()) {
            return $result->setHttpResponseCode(503)->setData(['error' => 'Agent not configured']);
        }

        // Anti-replay: 300 second window
        if (abs(time() - $timestamp) > 300) {
            return $result->setHttpResponseCode(401)->setData(['error' => 'Timestamp outside replay window']);
        }

        // HMAC-SHA256 verification (Ticpan signs, Magento verifies — same shared secret)
        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->config->getSecretKey());
        if (! hash_equals($expected, $signature)) {
            return $result->setHttpResponseCode(401)->setData(['error' => 'Invalid signature']);
        }

        $scheduledAt = $this->scheduleNextRun();

        return $result->setHttpResponseCode(202)->setData(['status' => 'triggered', 'scheduled_at' => $scheduledAt]);
    }

    private function scheduleNextRun(): string
    {
        $now        = $this->dateTime->gmtTimestamp();
        $nextMinute = date('Y-m-d H:i:00', $now + 60);

        // Avoid duplicate: if a pending entry already exists, no need to add another
        $pending = $this->scheduleCollectionFactory->create()
            ->addFieldToFilter('job_code', self::JOB_CODE)
            ->addFieldToFilter('status', Schedule::STATUS_PENDING)
            ->getSize();

        if ($pending === 0) {
            /** @var Schedule $schedule */
            $schedule = $this->scheduleFactory->create();
            $schedule->setJobCode(self::JOB_CODE)
                ->setStatus(Schedule::STATUS_PENDING)
                ->setCreatedAt(date('Y-m-d H:i:s', $now))
                ->setScheduledAt($nextMinute)
                ->save();
        }

        return $nextMinute;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
