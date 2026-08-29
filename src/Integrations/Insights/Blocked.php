<?php

namespace Goldnead\Suppression\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\Suppression\Reasons;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many addresses may not be written to, as of the end of the period.
 *
 * ## A holding, not an event
 *
 * Its neighbour counts things that happened and adds up over a period. This one
 * counts things that *are*, and nothing is written at the moment an address
 * stops being blocked by a temporary suppression running out — the clock does
 * it, silently. So the question can only be asked **of an instant**:
 *
 *     suppressed_at <= T
 *     AND (released_at IS NULL OR released_at > T)
 *     AND (expires_at  IS NULL OR expires_at  > T)
 *
 * Three ways to read the number wrongly, all worth stating:
 *
 *  - **It does not add up.** Twelve months of 400 blocked addresses are the
 *    same 400 seen twelve times, not 4800 people.
 *  - **It is not the events figure minus the releases.** An address blocked
 *    before the period and still blocked is in this number and in no event of
 *    the period at all.
 *  - **An address blocked and released inside the period is not in it.** Both
 *    of those are events and both are counted next door; at the moment we ask,
 *    the address may be mailed.
 *
 * ## Addresses, not rows
 *
 * `count(distinct email_normalized)`, and the distinction is real rather than
 * pedantic. One row per (brand, address) is what the unique index allows, and
 * the reason scoping in {@see Reasons} means the same
 * mailbox can legitimately hold two rows: a global hard bounce (`brand_id = 0`)
 * and one brand's own complaint. Those are two different facts, and they are
 * one address that cannot be mailed. Counting rows would report it as two and
 * the number would drift upwards on exactly the installations that have more
 * than one brand.
 *
 * The price is that the series cannot be built as a running balance the way an
 * entity count can — a distinct count does not decompose into arrivals and
 * departures, because two rows leaving may free one address or none. So each
 * bucket is asked its own question. That is one query per column, which is why
 * {@see MAX_BUCKETS} exists; Insights itself switches to monthly grain past a
 * quarter, so in practice it is twelve.
 *
 * ## What the state table cannot tell us
 *
 * `suppressions` is current state, not history. Releasing an address sets
 * `released_at`; blocking it again **clears that column and moves
 * `suppressed_at` forward**. So for an address that has been round the loop,
 * this figure reports the address as blocked since its latest block, and the
 * gap in the middle is gone. The append-only account of it is in
 * `suppression_events` and is what the events figure reads.
 *
 * Reconstructing the holding from the event log instead was considered and
 * rejected: expiry produces no event at all — it is the clock, not an action —
 * so a series built from events would carry every temporary block forever and
 * be wrong for every installation, rather than approximate for the addresses
 * that have been released and blocked again.
 */
class Blocked extends SuppressionMetric
{
    /**
     * A hard stop on how many buckets a chart may have.
     *
     * Each one costs a query here, which is the trade the distinct count buys.
     * The recent end is kept, because that is the end anybody looking at a
     * level cares about.
     */
    private const MAX_BUCKETS = 400;

    protected function table(): string
    {
        return 'suppressions';
    }

    /**
     * The column an address enters the holding on.
     *
     * Declared because {@see TableMetric}
     * requires one, and it is genuinely the arrival marker — but nothing here
     * windows on it the way an event metric does. Leaving is two other columns,
     * and the whole point of the class is the three together.
     */
    protected function timestamp(): string
    {
        return 'suppressed_at';
    }

    public function handle(): string
    {
        return 'suppression.active';
    }

    public function label(): string
    {
        return __('suppression::insights.metric_active');
    }

    public function description(): ?string
    {
        return __('suppression::insights.metric_active_description');
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

        return $this->blockedAt($this->bounds($query)[1]);
    }

    /**
     * The holding at the end of every bucket.
     *
     * A bucket whose holding is zero is left out rather than written: Insights
     * fills an omitted bucket with zero, and for a level "no bar" and "nobody
     * blocked" are the same statement. That is only true because this is a
     * count — a rate could not be omitted, which is why a rate returns an
     * explicit null instead.
     *
     * @return array<string, int>
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        [$from, $end] = $this->bounds($query);

        $start = $from ?? $this->firstBlock();

        if ($start === null || $start->greaterThan($end)) {
            return [];
        }

        $series = [];

        foreach ($this->bucketEnds($start, $end, $query) as $bucket => $moment) {
            $blocked = $this->blockedAt($moment);

            if ($blocked !== 0) {
                $series[$bucket] = $blocked;
            }
        }

        return $series;
    }

    // -- The queries --------------------------------------------------------

    /** How many distinct addresses were blocked at one instant. */
    protected function blockedAt(Carbon $moment): int
    {
        return (int) $this->rowsBlockedAt($moment)->distinct()->count('email_normalized');
    }

    /**
     * The first instant **after** a moment, so nothing is compared inclusively.
     *
     * The same device as {@see Period::toExclusive()}, and here for the same
     * reason. Every moment this class asks about is the
     * end of something — a bucket's `endOfDay`, a month's `endOfMonth`, the
     * period's own `to` — and all three are 23:59:59.999999. A query binding
     * formats a date as `Y-m-d H:i:s` and drops the fraction, so compared with
     * `<=` against a column that keeps milliseconds, an address blocked at
     * 23:59:59.500 was not yet on the list at the close of that day, and one
     * released in that same fraction was still on it. Silently, and only where
     * the fraction survives.
     *
     * Moved to the following midnight there is nothing left to truncate:
     * `suppressed_at < T'` is exactly `suppressed_at <= T`, and
     * `released_at >= T'` is exactly `released_at > T`, at every precision.
     *
     * The bucket keys are untouched by this: {@see bucketEnds()} still walks
     * the calendar on the inclusive end, so no column is added or lost — only
     * the question asked in each one is stated without a boundary that can be
     * rounded away.
     */
    protected function justAfter(Carbon $moment): Carbon
    {
        return $moment->copy()->addSecond()->startOfSecond();
    }

    /**
     * The definition, in one place.
     *
     * Everything else in this class is a way of asking it repeatedly.
     */
    protected function rowsBlockedAt(Carbon $moment): Builder
    {
        $bis = $this->justAfter($moment);

        return DB::table($this->table())
            ->where('suppressed_at', '<', $bis)
            ->where(fn (Builder $rows) => $rows->whereNull('released_at')->orWhere('released_at', '>=', $bis))
            ->where(fn (Builder $rows) => $rows->whereNull('expires_at')->orWhere('expires_at', '>=', $bis));
    }

    /** The oldest block on record, for a period that names no start. */
    protected function firstBlock(): ?Carbon
    {
        $oldest = DB::table($this->table())->min('suppressed_at');

        return $oldest === null ? null : Carbon::parse((string) $oldest);
    }

    // -- Bucketing ----------------------------------------------------------

    /**
     * Every bucket the period covers, with the instant its column stands for.
     *
     * Enumerated in PHP rather than read off the data, because a holding has a
     * value in a bucket where nothing at all happened — that is the difference
     * between a level and an event, and a series built from the buckets that
     * have rows would draw a quiet month as an empty one.
     *
     * The last bucket is cut off at the period's own end rather than at its
     * calendar end: a month-to-date column must say what is true today, not
     * what will be true on the 31st.
     *
     * @return array<string, Carbon>
     */
    protected function bucketEnds(Carbon $start, Carbon $end, MetricQuery $query): array
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();

        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $schluss = $monthly ? $cursor->copy()->endOfMonth() : $cursor->copy()->endOfDay();

            $buckets[$cursor->format($monthly ? 'Y-m' : 'Y-m-d')] = $schluss->greaterThan($end)
                ? $end->copy()
                : $schluss;

            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return count($buckets) > self::MAX_BUCKETS
            ? array_slice($buckets, -self::MAX_BUCKETS, null, true)
            : $buckets;
    }
}
