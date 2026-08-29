<?php

namespace Goldnead\Suppression\Integrations\Insights;

use Goldnead\BrandContext\Scopes\BrandScope;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\Suppression\Models\Suppression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * What both figures this addon offers the analytics addon have in common.
 *
 * The coupling runs one way and is optional in both directions:
 * `goldnead/statamic-insights` is a `suggest`, never a `require`, and the
 * classes here are only ever loaded once the service provider has seen the
 * sibling's facade. Loading this file *means* the sibling is installed —
 * {@see TableMetric} lives in its package.
 *
 * Four decisions shape the numbers below.
 *
 * 1. **The two tables answer two different questions, and neither can answer
 *    the other's.** `suppression_events` is append-only history: it can say
 *    what happened and when, and it is the only honest source for that.
 *    `suppressions` is current state: one row per (brand, address), rewritten
 *    when an address is released or blocked again. So the event figure reads
 *    the log and the holding figure reads the state, and neither is derived
 *    from the other.
 * 2. **Timestamps are chosen.** Both tables carry `created_at` and neither
 *    metric uses it. A provider's bounce notification is written whenever the
 *    webhook was processed — after a retry, possibly hours later — and the
 *    bounce happened when the provider says it did. `occurred_at` and
 *    `suppressed_at` are the columns that mean something.
 * 3. **The numbers are across every brand, and both of them say so on the
 *    screen.** This is the one figure family in the group that does not stop at
 *    the brand boundary, and it is deliberate rather than forgotten — see
 *    {@see brandColumn()} for why the ordinary filter cannot be used on these
 *    two tables at all. What matters as much as the decision is that a reader
 *    can see it: a screen where one tile counts one brand and the tile beside
 *    it counts four, with nothing on either saying which, is worse than a
 *    screen that knows no brands. So it is written into the description of both
 *    figures, in the reader's own language, and a test holds it there.
 * 4. **Nothing to measure is not measuring nothing.** {@see available()} is
 *    false without the table and false when the bridge is switched off, so the
 *    figures leave the screen instead of stating a confident zero for a site
 *    that has never sent an email.
 *
 * ## No timezone shift here, deliberately
 *
 * A sibling in this family (`statamic-entitlements`) stores UTC on disk and has
 * to move the window before comparing. This one does not: its columns go
 * through Laravel's ordinary `datetime` cast, which writes whatever Carbon it
 * is given in that value's own zone — and everything writing here uses
 * `now()`, which is the application timezone. Insights builds its period from
 * `Carbon::now()`, in the same zone. Shifting the instants would introduce the
 * very skew the conversion elsewhere removes. {@see inAppZone()} restates the
 * bounds in that zone without moving them, which is a different thing and
 * matters only for a caller that phrased its range somewhere else.
 */
abstract class SuppressionMetric extends TableMetric
{
    public function group(): string
    {
        return __('suppression::insights.metric_group');
    }

    /**
     * The table has to be there, and the bridge has to be wanted.
     *
     * A switch rather than a hard-wired yes: an installation that has the
     * analytics addon for something else entirely can turn these figures off
     * without uninstalling anything.
     */
    public function available(): bool
    {
        return (bool) config('suppression.insights.enabled', true) && parent::available();
    }

    /**
     * No brand filter, and not for want of a column.
     *
     * Both tables carry `brand_id`, so declaring it here is one line — and it
     * would be the wrong line. **In this package 0 does not mean "no brand", it
     * means "every brand".** A hard bounce is recorded globally on purpose: an
     * address that does not exist does not exist for any of the brands, and the
     * send path refuses it for all of them. A brand's own complaints sit beside
     * those as separate rows, which is what the unique index `(brand, address)`
     * is for.
     *
     * {@see TableMetric::brandScoped()} is a faithful transcription of
     * {@see BrandScope}, and that scope compares `brand_id = currentId`.
     * Applied here it would drop every global row — the hard bounces, which are
     * the bulk of any real list — and report a brand's blocked addresses as a
     * fraction of what actually stops its sends. This package already refused
     * that scope on its models for exactly that reason: {@see Suppression}
     * replaces it with `scopeVisibleTo()`, which is `brand_id in (0, current)`.
     *
     * That rule cannot be stated through {@see TableMetric::brandColumn()}: it
     * offers a column and an equality, not a set. Re-implementing it here would
     * mean transcribing the brand manager's decision ladder — bypass,
     * single-brand, unresolved, fail mode — into this package a second time,
     * which is precisely the copying that left these metrics with a window
     * three repairs behind the one they inherited. So the figures stay across
     * every brand until the base class can express a set rather than an
     * equality, and both of them say so in their {@see Metric::description()}.
     */
    protected function brandColumn(): ?string
    {
        return null;
    }

    /**
     * The window, never open at the far end.
     *
     * The start may be absent — "all time" genuinely has no beginning. The end
     * may not: `suppressions.expires_at` is routinely in the future, and an
     * unbounded upper edge would report a temporary block that runs until
     * December as one that has already lapsed. So "all" means everything **so
     * far**, and now is the far edge — which for the event figure is what
     * {@see TableMetric::untilNow()} does. Kept as its own accessor because
     * {@see Blocked} needs the two instants themselves rather than a query: a
     * holding is asked *of a moment*.
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    protected function bounds(MetricQuery $query): array
    {
        // Moved into the application timezone rather than passed through.
        // Insights builds its period from `Carbon::now()` and hands down bounds
        // that already are in it, so this normally changes nothing — but the
        // query grammar formats a bound in *its own* zone, and a caller that
        // stated a range in Europe/Berlin would otherwise compare Berlin wall
        // clock against a column written in the site's. Two hours, silently.
        $zone = date_default_timezone_get();

        return [
            $query->period->from?->copy()->setTimezone($zone),
            ($query->period->to ?? Carbon::now())->copy()->setTimezone($zone),
        ];
    }

    /**
     * The same question, with its window stated in the application timezone.
     *
     * Normally a no-op: Insights builds its period from `Carbon::now()`, which
     * already carries that zone, and these columns are written by `now()` in the
     * same one. It earns its keep when a caller states a range in a zone of its
     * own — the query grammar formats a bound in *its* Carbon's zone, and Berlin
     * wall clock against a column written in Chicago is two hours, silently.
     * The instant is unchanged; only its spelling is.
     *
     * An open-ended period has no bounds to restate and is handed on as it came.
     */
    protected function inAppZone(MetricQuery $query): MetricQuery
    {
        if ($query->period->from === null || $query->period->to === null) {
            return $query;
        }

        $zone = date_default_timezone_get();

        return new MetricQuery(
            Period::between(
                $query->period->from->copy()->setTimezone($zone),
                $query->period->to->copy()->setTimezone($zone),
            ),
            $query->bucket,
            $query->filters,
        );
    }

    /**
     * The rows inside the window.
     *
     * Extended rather than rewritten, and the whole of the extension is the line
     * above: the bounds are restated in the application timezone first.
     * Everything else about the window is
     * {@see TableMetric::inPeriod()}'s — the `whereNotNull`, and the half-open
     * upper bound in particular.
     *
     * It was a full copy once, and that is what this file is a correction of.
     * The copy compared `<= 23:59:59.999999`, a binding formats a date as
     * `Y-m-d H:i:s` and drops the fraction, and so a suppression stamped at
     * 23:59:59.5 on the closing day of the period fell out of the figure without
     * a trace. The base class had been comparing `< midnight` for some time; the
     * copy never heard about it.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($this->inAppZone($query), $column);
    }

    /**
     * The words for a row that has no value in the dimension it is split by.
     *
     * Per dimension: "no reason given" and "no source" read differently, and a
     * shared dash tells a reader nothing about which of the two they are
     * looking at.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('suppression::insights.metric_no_'.$dimension);
    }
}
