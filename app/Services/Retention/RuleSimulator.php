<?php

namespace App\Services\Retention;

use App\Contracts\EmailProviderInterface;
use App\Models\RetentionRule;
use App\Services\Providers\MessageMetadata;

class RuleSimulator
{
    private const COUNT_CAP = 10000;

    private const SAMPLE_SIZE = 20;

    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly TargetLabel $targetLabel,
    ) {}

    public function simulate(RetentionRule $rule): SimulationResult
    {
        $query = RuleQuery::for($rule, $this->targetLabel->nameFor($rule));
        $ids = $this->provider->listAllMessageIds($rule->account, $query->query, $query->labelIds, self::COUNT_CAP + 1);

        $capped = count($ids) > self::COUNT_CAP;
        $total = min(count($ids), self::COUNT_CAP);

        // Listings come newest first, so the tail holds the oldest matches.
        $sampleIds = array_slice(array_slice($ids, 0, self::COUNT_CAP), -self::SAMPLE_SIZE);
        $sample = collect($sampleIds === [] ? [] : $this->provider->fetchMetadata($rule->account, $sampleIds))
            ->sortBy(fn (MessageMetadata $message) => $message->internalDate->getTimestamp())
            ->map(fn (MessageMetadata $message) => [
                'sender' => (string) $message->from,
                'subject' => $message->subject,
                'date' => $message->internalDate->toIso8601String(),
            ])
            ->values()
            ->all();

        $rule->forceFill(['simulated_at' => now()])->save();
        $rule->logExecution('simulated', $total);

        return new SimulationResult($total, $capped, $sample);
    }
}
