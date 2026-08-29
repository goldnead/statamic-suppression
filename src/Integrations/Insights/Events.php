<?php

namespace Goldnead\Suppression\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\Suppression\Models\SuppressionEvent;
use Goldnead\Suppression\Reasons;

/**
 * How much happened on the suppression list.
 *
 * Over `suppression_events`, which is append-only and never edited or deleted —
 * the one table here that can honestly answer "what happened, and when". The
 * state table cannot: it holds one row per address and rewrites it, so an
 * address blocked, released and blocked again leaves a single row that says
 * only the last of those three things.
 *
 * **On `occurred_at`, not `created_at`.** The two differ by however long the
 * webhook took to arrive and by every retry in between, and a provider that
 * redelivers a day-old bounce would otherwise pile it onto today. `occurred_at`
 * is the provider's own moment, which is the one a deliverability figure is
 * about.
 *
 * **Across every brand**, which is unusual on that screen and is said out loud
 * in the metric's own description rather than only here — see
 * {@see SuppressionMetric::brandColumn()} for why the ordinary brand filter
 * cannot be applied to a table where 0 means "all of them".
 *
 * A deliberately mixed figure: a block and a release are both events, and they
 * point in opposite directions. That is why the split by `event_type` is the
 * first one offered rather than an afterthought — the headline says how busy
 * the list was, and the split says which way. Netting them into one number
 * would hide a month in which two hundred addresses bounced and a hundred and
 * ninety were released again.
 *
 * Soft bounces are in here too, and they are the reason the count is usually
 * larger than anybody expects: a single soft bounce is never a suppression —
 * only a run of them inside the window promotes an address — so they are events
 * without consequence, recorded so the threshold can be computed at all.
 */
class Events extends SuppressionMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'suppression_events';
    }

    protected function timestamp(): string
    {
        return 'occurred_at';
    }

    public function handle(): string
    {
        return 'suppression.events';
    }

    public function label(): string
    {
        return __('suppression::insights.metric_events');
    }

    public function description(): ?string
    {
        return __('suppression::insights.metric_events_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return [
            'event_type' => __('suppression::insights.metric_breakdown_event_type'),
            'reason' => __('suppression::insights.metric_breakdown_reason'),
            'source' => __('suppression::insights.metric_breakdown_source'),
        ];
    }

    /**
     * The split, with the handles turned into something a person reads.
     *
     * `event_type` and `reason` are both closed lists — five constants on
     * {@see SuppressionEvent} and six on {@see Reasons} — so they get real
     * words. A value from neither list keeps its raw handle rather than
     * vanishing or being labelled "unknown": rows written by an older release,
     * or by an importer, are still rows, and a reader can see what they say.
     *
     * `source` is a free string by design (an ESP name, a command, an import
     * job) and is shown as it was written.
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available()) {
            return [];
        }

        if (! array_key_exists($dimension, $this->breakdowns())) {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, $dimension, 'count(*)', $limit);

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] === null
                ? $this->missingLabel($dimension)
                : $this->readable($dimension, $row['key']),
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * A handle in the reader's language, or the handle itself.
     *
     * The lookup is against the constants rather than against whatever is in
     * the column, so a value nobody declared cannot silently acquire a
     * translation that means something else.
     */
    protected function readable(string $dimension, string $key): string
    {
        $known = match ($dimension) {
            'event_type' => [
                SuppressionEvent::TYPE_SUPPRESSED,
                SuppressionEvent::TYPE_RELEASED,
                SuppressionEvent::TYPE_SOFT_BOUNCE,
                SuppressionEvent::TYPE_REASSERTED,
                SuppressionEvent::TYPE_IMPORTED,
            ],
            'reason' => Reasons::all(),
            default => [],
        };

        if (! in_array($key, $known, true)) {
            return $key;
        }

        return __('suppression::insights.'.($dimension === 'event_type' ? 'event_' : 'reason_').$key);
    }
}
