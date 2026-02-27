<?php

declare(strict_types=1);

namespace Basis\Nats\Stream;

use DomainException;

class Configuration
{
    private array $subjects = [];
    private bool $allowRollupHeaders = true;
    private bool $denyDelete = true;
    private int $maxAge = 0;
    private int $maxConsumers = -1;
    private int $replicas = 1;
    private DiscardPolicy $discardPolicy = DiscardPolicy::OLD;
    private RetentionPolicy $retentionPolicy = RetentionPolicy::LIMITS;
    private StorageBackend $storageBackend = StorageBackend::FILE;

    private ?float $duplicateWindow = null;
    private ?int $maxBytes = null;
    private ?int $maxMessageSize = null;
    private ?int $maxMessagesPerSubject = null;
    private ?string $description = null;
    private ?array $consumerLimits = null;
    private ?bool $allowMsgSchedules = null;

    public function __construct(
        public readonly string $name
    ) {
    }

    public function fromArray(array $array): self
    {
        $this
            ->setDiscardPolicy(DiscardPolicy::from($array['discard']))
            ->setMaxConsumers($array['max_consumers'])
            ->setReplicas($array['replicas'] ?? $array['num_replicas'])
            ->setRetentionPolicy(RetentionPolicy::from($array['retention']))
            ->setStorageBackend(StorageBackend::from($array['storage']))
            ->setSubjects($array['subjects']);

        if (isset($array['allow_msg_schedules'])) {
            $this->setAllowMsgSchedules($array['allow_msg_schedules']);
        }

        return $this;
    }

    public function getAllowRollupHeaders(): bool
    {
        return $this->allowRollupHeaders;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDenyDelete(): bool
    {
        return $this->denyDelete;
    }

    public function getDiscardPolicy(): DiscardPolicy
    {
        return $this->discardPolicy;
    }

    public function getDuplicateWindow(): ?float
    {
        return $this->duplicateWindow;
    }

    public function getMaxAge(): int
    {
        return $this->maxAge;
    }

    public function getMaxBytes(): ?int
    {
        return $this->maxBytes;
    }

    public function getMaxConsumers(): int
    {
        return $this->maxConsumers;
    }

    public function getMaxMessageSize(): ?int
    {
        return $this->maxMessageSize;
    }

    public function getMaxMessagesPerSubject(): ?int
    {
        return $this->maxMessagesPerSubject;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getReplicas(): int
    {
        return $this->replicas;
    }

    public function getRetentionPolicy(): RetentionPolicy
    {
        return $this->retentionPolicy;
    }

    public function getStorageBackend(): StorageBackend
    {
        return $this->storageBackend;
    }

    public function getSubjects(): array
    {
        return $this->subjects;
    }

    public function setAllowRollupHeaders(bool $allowRollupHeaders): self
    {
        $this->allowRollupHeaders = $allowRollupHeaders;
        return $this;
    }

    public function setDenyDelete(bool $denyDelete): self
    {
        $this->denyDelete = $denyDelete;
        return $this;
    }

    public function setDiscardPolicy(DiscardPolicy $policy): self
    {
        $this->discardPolicy = $policy;
        return $this;
    }

    public function setDuplicateWindow(?float $seconds): self
    {
        $this->duplicateWindow = $seconds;
        return $this;
    }

    /**
     * set the max age in nanoSeconds
     */
    public function setMaxAge(int $maxAgeNanoSeconds): self
    {
        $this->maxAge = $maxAgeNanoSeconds;
        return $this;
    }

    public function setMaxBytes(?int $maxBytes): self
    {
        $this->maxBytes = $maxBytes;
        return $this;
    }

    public function setMaxConsumers(int $maxConsumers): self
    {
        $this->maxConsumers = $maxConsumers;
        return $this;
    }

    public function setMaxMessageSize(?int $maxMessageSize): self
    {
        $this->maxMessageSize = $maxMessageSize;
        return $this;
    }

    public function setMaxMessagesPerSubject(?int $maxMessagesPerSubject): self
    {
        $this->maxMessagesPerSubject = $maxMessagesPerSubject;
        return $this;
    }

    public function setReplicas(int $replicas): self
    {
        $this->replicas = $replicas;
        return $this;
    }

    public function setRetentionPolicy(RetentionPolicy $policy): self
    {
        $this->retentionPolicy = $policy;
        return $this;
    }

    public function setStorageBackend(StorageBackend $storage): self
    {
        $this->storageBackend = $storage;
        return $this;
    }

    public function setSubjects(array $subjects): self
    {
        $this->subjects = $subjects;
        return $this;
    }

    public function setConsumerLimits(array $consumerLimits): self
    {
        $this->consumerLimits = ConsumerLimits::validate($consumerLimits);
        return $this;
    }

    public function getConsumerLimits(): ?array
    {
        return $this->consumerLimits;
    }

    /**
     * Enable or disable message scheduling on this stream.
     *
     * When set to true, the stream accepts messages with scheduling headers
     * (Nats-Schedule, Nats-Schedule-Target) that instruct the NATS server to
     * deliver messages to a target subject at a specified future time.
     *
     * Requires NATS Server >= 2.12. Once enabled on a stream, this setting
     * cannot be disabled.
     *
     * Supported schedule formats:
     *  - Single delayed: "@at <RFC3339 timestamp>" (e.g. "@at 2026-03-01T12:00:00Z")
     *  - Cron (6-field): "seconds minutes hours day-of-month month day-of-week"
     *  - Predefined: "@yearly", "@monthly", "@weekly", "@daily", "@hourly"
     *  - Interval: "@every <duration>" (e.g. "@every 5m")
     *
     * @param bool|null $allowMsgSchedules true to enable, false to disable, null to omit from config
     * @return self
     *
     * @see https://docs.nats.io/nats-concepts/jetstream/streams
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     * @see https://nats.io/blog/nats-server-2.12-release/
     */
    public function setAllowMsgSchedules(?bool $allowMsgSchedules): self
    {
        $this->allowMsgSchedules = $allowMsgSchedules;
        return $this;
    }

    /**
     * Get whether message scheduling is enabled on this stream.
     *
     * Returns true if scheduling is enabled, false if explicitly disabled,
     * or null if the setting was not provided (server default: disabled).
     *
     * @return bool|null
     *
     * @see https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md
     */
    public function getAllowMsgSchedules(): ?bool
    {
        return $this->allowMsgSchedules;
    }

    public function toArray(): array
    {
        $config = [
            'allow_rollup_hdrs' => $this->getAllowRollupHeaders(),
            'deny_delete' => $this->getDenyDelete(),
            'description' => $this->getDescription(),
            'discard' => $this->discardPolicy->value,
            'duplicate_window' => ($dw = $this->getDuplicateWindow()) !== null ? (int)($dw * 1_000_000_000) : null,
            'max_age' => $this->getMaxAge(),
            'max_bytes' => $this->getMaxBytes(),
            'max_consumers' => $this->getMaxConsumers(),
            'max_msg_size' => $this->getMaxMessageSize(),
            'max_msgs_per_subject' => $this->getMaxMessagesPerSubject(),
            'name' => $this->getName(),
            'num_replicas' => $this->getReplicas(),
            'retention' => $this->retentionPolicy->value,
            'storage' => $this->storageBackend->value,
            'subjects' => $this->getSubjects(),
            'consumer_limits' => $this->getConsumerLimits(),
            'allow_msg_schedules' => $this->getAllowMsgSchedules(),
        ];

        foreach ($config as $k => $v) {
            if ($v === null) {
                unset($config[$k]);
            }
        }

        return $config;
    }

    public function validateSubject(string $subject): string
    {
        if (!in_array($subject, $this->getSubjects())) {
            throw new DomainException("Invalid subject $subject");
        }

        return $subject;
    }
}
