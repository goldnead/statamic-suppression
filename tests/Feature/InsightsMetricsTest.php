<?php

namespace Goldnead\Suppression\Tests\Feature;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\Suppression\Integrations\Insights\Blocked;
use Goldnead\Suppression\Integrations\Insights\Events;
use Goldnead\Suppression\Integrations\Insights\SuppressionMetric;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Models\SuppressionEvent;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two numbers this addon offers the analytics addon.
 *
 * **Every expectation below is worked out by hand from one fixture.** Eleven
 * events and six suppression rows, small enough to add up on paper, with the
 * awkward cases in them: an address that is on the list twice (globally and for
 * one brand), a temporary block that lapses inside the period, a release inside
 * the period, an event with no reason, an event with no source, a block that
 * predates the window, and a block whose expiry is still in the future.
 *
 * Tested against a stand-in for the contract rather than the real package: the
 * sibling is a `suggest`, and a test that needed it installed would be proving
 * the opposite of what this addon claims. `tests/Fakes/insights-contracts.php`
 * explains why that is a required file and not an autoload entry, and
 * `InsightsContractsMatchTest` is what holds the copy to account.
 *
 * A PHPUnit class rather than a Pest file, for one mechanical reason: the
 * stand-ins have to be loaded **before the application boots** — the contracts
 * before a metric class is touched, the facade before the provider's `booted()`
 * callback asks whether it exists. `beforeEach()` runs after the bed is up, and
 * a callback that has already run cannot be given a second chance.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected function setUp(): void
    {
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        // The base class lies beside them as a file of its own and carries no
        // guard in its head: it is a byte-for-byte copy of the original, so the
        // guard sits here instead. See InsightsContractsMatchTest.
        if (! class_exists(TableMetric::class, false)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and in URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Six rows on the list and eleven events, ten of them inside the window.
     *
     * The list, by address:
     *
     * | address | brand | blocked | ends                              |
     * |---------|-------|---------|-----------------------------------|
     * | a@      | 0     | 08-12   | never                             |
     * | a@      | 3     | 08-15   | never — the same address, twice    |
     * | b@      | 3     | 08-14   | released 08-18                    |
     * | c@      | 0     | 08-13   | expires 08-17 08:00               |
     * | d@      | 0     | 07-01   | never — already on before the window |
     * | e@      | 0     | 08-19   | expires 09-30, so still running   |
     */
    protected function fixture(): void
    {
        // The two rows for one address. A global hard bounce and one brand's own
        // complaint are two different facts about the same mailbox, and the
        // unique index is (brand, address) precisely so both can be recorded.
        $this->block('a@example.com', Reasons::HARD_BOUNCE, '2026-08-12 09:00:00');
        $this->block('a@example.com', Reasons::COMPLAINT, '2026-08-15 09:00:00', brandId: 3);

        $this->block('b@example.com', Reasons::COMPLAINT, '2026-08-14 10:00:00', brandId: 3, releasedAt: '2026-08-18 10:00:00');

        // A temporary block that lapses inside the window. Nothing is written
        // when it does — the clock does it — which is the whole reason the
        // holding cannot be derived from the event log.
        $this->block('c@example.com', Reasons::SOFT_BOUNCE_THRESHOLD, '2026-08-13 08:00:00', expiresAt: '2026-08-17 08:00:00');

        $this->block('d@example.com', Reasons::HARD_BOUNCE, '2026-07-01 09:00:00');

        // A temporary block whose expiry is still ahead. On an unbounded period
        // it must not be reported as already lapsed.
        $this->block('e@example.com', Reasons::MANUAL, '2026-08-19 09:00:00', expiresAt: '2026-09-30 09:00:00');

        // The log. Four blocks, three soft bounces, two releases, one import —
        // deliberately four different totals, so the order of the split is a
        // fact and not a coin toss.
        $this->event(SuppressionEvent::TYPE_SUPPRESSED, '2026-08-12 09:00:00', Reasons::HARD_BOUNCE, 'scaleway', 'a@example.com');
        $this->event(SuppressionEvent::TYPE_SUPPRESSED, '2026-08-14 10:00:00', Reasons::COMPLAINT, 'scaleway', 'b@example.com', brandId: 3);
        $this->event(SuppressionEvent::TYPE_SUPPRESSED, '2026-08-13 08:30:00', Reasons::SOFT_BOUNCE_THRESHOLD, 'scaleway', 'c@example.com');

        // Blocked by hand: no source, because nothing external reported it.
        $this->event(SuppressionEvent::TYPE_SUPPRESSED, '2026-08-19 09:00:00', Reasons::MANUAL, null, 'e@example.com');

        // Soft bounces carry no reason: a full mailbox is a fact about today and
        // never a suppression on its own.
        $this->event(SuppressionEvent::TYPE_SOFT_BOUNCE, '2026-08-16 12:00:00', null, 'scaleway', 'f@example.com');
        $this->event(SuppressionEvent::TYPE_SOFT_BOUNCE, '2026-08-16 13:00:00', null, 'scaleway', 'f@example.com');
        $this->event(SuppressionEvent::TYPE_SOFT_BOUNCE, '2026-08-12 14:00:00', null, 'scaleway', 'g@example.com');

        $this->event(SuppressionEvent::TYPE_RELEASED, '2026-08-18 10:00:00', null, null, 'b@example.com', brandId: 3);
        $this->event(SuppressionEvent::TYPE_RELEASED, '2026-08-15 11:00:00', null, null, 'h@example.com');

        $this->event(SuppressionEvent::TYPE_IMPORTED, '2026-08-13 08:00:00', Reasons::PROVIDER_IMPORT, 'brevo', 'i@example.com');

        // Six weeks earlier, and in no figure of this period.
        $this->event(SuppressionEvent::TYPE_SUPPRESSED, '2026-07-02 09:00:00', Reasons::HARD_BOUNCE, 'scaleway', 'j@example.com');
    }

    protected function block(
        string $email,
        string $reason,
        string $suppressedAt,
        ?string $expiresAt = null,
        ?string $releasedAt = null,
        int $brandId = Reasons::GLOBAL_BRAND_ID,
    ): Suppression {
        return Suppression::create([
            'brand_id' => $brandId,
            'email_normalized' => $email,
            'reason' => $reason,
            'source' => 'scaleway',
            'suppressed_at' => $suppressedAt,
            'expires_at' => $expiresAt,
            'released_at' => $releasedAt,
            'released_by' => $releasedAt === null ? null : 'adrian',
            'release_reason' => $releasedAt === null ? null : 'Der Empfänger hat sich selbst wieder angemeldet.',
        ]);
    }

    protected function event(
        string $type,
        string $occurredAt,
        ?string $reason,
        ?string $source,
        string $email,
        int $brandId = Reasons::GLOBAL_BRAND_ID,
    ): SuppressionEvent {
        return SuppressionEvent::create([
            'brand_id' => $brandId,
            'email_normalized' => $email,
            'event_type' => $type,
            'reason' => $reason,
            'source' => $source,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** The ten days the fixture lives in. */
    protected function frage(string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(
                Carbon::parse('2026-08-11')->startOfDay(),
                Carbon::parse('2026-08-20')->endOfDay(),
            ),
            $bucket,
        );
    }

    /** @return array<string, int|float> */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- The two numbers ----------------------------------------------------

    /**
     * Both figures at once, against hand-worked totals.
     *
     * One test rather than two: they are read side by side and have to agree
     * about the same fixture.
     */
    #[Test]
    public function both_figures_match_what_the_fixture_says(): void
    {
        $this->fixture();

        $this->assertSame(10, (new Events)->value($this->frage()), 'four blocks, three soft bounces, two releases, one import; the July one is outside');
        $this->assertSame(3, (new Blocked)->value($this->frage()), 'a@, d@ and e@ — b@ was released, c@ lapsed');
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        foreach ([[Events::class, 'suppression.events'], [Blocked::class, 'suppression.active']] as [$klasse, $handle]) {
            /** @var SuppressionMetric $metrik */
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame(Unit::COUNT, $metrik->unit());
            $this->assertSame(__('suppression::insights.metric_group'), $metrik->group());

            // Translated, not hard-coded: a missing key comes back as the key
            // itself, which is what these assertions catch.
            $this->assertNotSame('', $metrik->label());
            $this->assertStringNotContainsString('insights.metric_', (string) $metrik->label());
            $this->assertNotEmpty($metrik->description());
            $this->assertStringNotContainsString('insights.metric_', (string) $metrik->description());

            $this->assertSame([], $metrik->meta($this->frage()));
        }
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No table, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident 0 on
     * a dashboard for a site that has never sent an email.
     */
    #[Test]
    public function a_metric_cannot_answer_without_its_table(): void
    {
        $this->assertTrue((new Events)->available());
        $this->assertTrue((new Blocked)->available());

        // A second, empty database rather than dropping the tables in this one.
        // Dropping them would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_sperrliste', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_sperrliste');
        DB::setDefaultConnection('ohne_sperrliste');

        try {
            foreach ([Events::class, Blocked::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' answered without its table.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' produced a figure without a table.');
                $this->assertSame([], $metrik->series($this->frage()));
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    /** And the same when the bridge is switched off, table or no table. */
    #[Test]
    public function the_bridge_can_be_switched_off_without_uninstalling_anything(): void
    {
        $this->fixture();

        config()->set('suppression.insights.enabled', false);

        foreach ([Events::class, Blocked::class] as $klasse) {
            $metrik = new $klasse;

            $this->assertFalse($metrik->available(), $klasse.' ignored the switch.');
            $this->assertNull($metrik->value($this->frage()));
            $this->assertSame([], $metrik->series($this->frage()));
        }

        $this->assertSame([], (new Events)->breakdown($this->frage(), 'event_type'));
    }

    // -- The splits ---------------------------------------------------------

    /**
     * What happened, split by which way it pointed.
     *
     * The headline says how busy the list was and this says what it was busy
     * with — a month of two hundred blocks and one of two hundred releases have
     * the same headline and opposite meanings.
     */
    #[Test]
    public function events_split_by_type_and_are_named_in_words(): void
    {
        $this->fixture();

        $zeilen = (new Events)->breakdown($this->frage(), 'event_type');

        $this->assertSame(
            ['suppressed' => 4, 'soft_bounce' => 3, 'released' => 2, 'imported' => 1],
            $this->keyed($zeilen),
        );

        $this->assertSame(10, array_sum(array_column($zeilen, 'value')), 'the split must add up to the figure it splits');

        // The handle goes in the URL; the label goes on the screen.
        $this->assertSame(__('suppression::insights.event_suppressed'), $zeilen[0]['label']);
        $this->assertSame(__('suppression::insights.event_imported'), $zeilen[3]['label']);
    }

    /**
     * An event with no source is a row keyed null, not a missing row.
     *
     * A report that quietly excludes rows is the hardest kind of wrong to
     * notice: the columns still add up among themselves, and only the total
     * disagrees — which is the number nobody re-adds.
     */
    #[Test]
    public function an_event_without_a_source_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new Events)->breakdown($this->frage(), 'source');

        $this->assertSame(['scaleway' => 6, '' => 3, 'brevo' => 1], $this->keyed($zeilen));
        $this->assertSame(10, array_sum(array_column($zeilen, 'value')));

        $this->assertNull($zeilen[1]['key'], 'a missing source must arrive as a null key');
        $this->assertSame(__('suppression::insights.metric_no_source'), $zeilen[1]['label']);
    }

    /** And so is an event with no reason — of which soft bounces are all three. */
    #[Test]
    public function an_event_without_a_reason_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new Events)->breakdown($this->frage(), 'reason');

        $this->assertCount(6, $zeilen, 'five named reasons and the rows that have none');
        $this->assertSame(10, array_sum(array_column($zeilen, 'value')));

        // Largest first, and the reasonless rows are the largest group: three
        // soft bounces and two releases.
        $this->assertNull($zeilen[0]['key']);
        $this->assertSame(5, $zeilen[0]['value']);
        $this->assertSame(__('suppression::insights.metric_no_reason'), $zeilen[0]['label']);

        // The named ones are read in words rather than as handles.
        $benannt = $this->keyed(array_slice($zeilen, 1));

        // Canonicalised, not ordered: all five named reasons occur once here,
        // and asserting an order between equal values would be asserting
        // whatever the database felt like.
        $this->assertEqualsCanonicalizing(
            [Reasons::HARD_BOUNCE, Reasons::COMPLAINT, Reasons::SOFT_BOUNCE_THRESHOLD, Reasons::MANUAL, Reasons::PROVIDER_IMPORT],
            array_keys($benannt),
        );

        $this->assertSame([1, 1, 1, 1, 1], array_values($benannt));

        $etiketten = array_column(array_slice($zeilen, 1), 'label');

        $this->assertContains(__('suppression::insights.reason_hard_bounce'), $etiketten);
        $this->assertNotContains(Reasons::HARD_BOUNCE, $etiketten);
    }

    /**
     * A value nobody declared keeps its raw handle rather than borrowing a name.
     *
     * Rows written by an older release, or by an importer that invented its own
     * vocabulary, are still rows. Translating whatever is in the column would
     * mean a handle nobody knows quietly acquiring the words of one that is.
     */
    #[Test]
    public function an_unknown_handle_is_shown_as_it_was_written(): void
    {
        $this->event('quarantined', '2026-08-14 09:00:00', 'unknown_reason', 'scaleway', 'k@example.com');

        $typen = $this->beschriftet((new Events)->breakdown($this->frage(), 'event_type'));
        $gruende = $this->beschriftet((new Events)->breakdown($this->frage(), 'reason'));

        $this->assertSame('quarantined', $typen['quarantined']);
        $this->assertSame('unknown_reason', $gruende['unknown_reason']);
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new Events)->breakdown($this->frage(), 'weather'));

        // Not a column of the table either — a dimension has to be offered, not
        // merely exist, or a screen could name any column it liked.
        $this->assertSame([], (new Events)->breakdown($this->frage(), 'email_normalized'));

        $this->assertSame(['event_type', 'reason', 'source'], array_keys((new Events)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Events)->breakdown($this->frage(), 'event_type', 2);

        $this->assertCount(2, $zeilen);
        $this->assertSame(['suppressed', 'soft_bounce'], array_column($zeilen, 'key'));
    }

    // -- Over time ----------------------------------------------------------

    /**
     * An event series holds only the buckets something happened in.
     *
     * The empty days are Insights' job — it fills the range for every metric at
     * once. A metric that filled its own would be filling them twice.
     */
    #[Test]
    public function the_event_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();

        $this->assertSame([
            '2026-08-12' => 2,
            '2026-08-13' => 2,
            '2026-08-14' => 1,
            '2026-08-15' => 1,
            '2026-08-16' => 2,
            '2026-08-18' => 1,
            '2026-08-19' => 1,
        ], (new Events)->series($this->frage()));
    }

    /**
     * The holding is a level and carries across days nothing happened on.
     *
     * Read off the fixture day by day. The 17th is where the temporary block on
     * c@ lapses without anybody writing anything, and the 18th is where b@ is
     * released — the two ways off the list, and neither of them adds up from
     * the event series.
     */
    #[Test]
    public function the_holding_is_a_level_and_not_a_count_of_events(): void
    {
        $this->fixture();

        $reihe = (new Blocked)->series($this->frage());

        $this->assertSame([
            '2026-08-11' => 1,
            '2026-08-12' => 2,
            '2026-08-13' => 3,
            '2026-08-14' => 4,
            // a@ acquires a second row on the 15th and is still one address.
            '2026-08-15' => 4,
            '2026-08-16' => 4,
            '2026-08-17' => 3,
            '2026-08-18' => 2,
            '2026-08-19' => 3,
            '2026-08-20' => 3,
        ], $reihe);

        // The last bucket and the headline are the same question asked at the
        // same instant, and they have to agree.
        $this->assertSame(3, (new Blocked)->value($this->frage()));
        $this->assertSame(3, end($reihe));
    }

    /**
     * One address on the list twice is one address.
     *
     * A global hard bounce and a brand's own complaint are two legitimate rows
     * for one mailbox, and counting rows would report it as two people. On an
     * installation with several brands that error compounds quietly, which is
     * why the figure counts distinct addresses and this test names the row that
     * would otherwise double it.
     */
    #[Test]
    public function an_address_on_the_list_twice_is_counted_once(): void
    {
        $this->fixture();

        $this->assertSame(
            2,
            (int) DB::table('suppressions')->where('email_normalized', 'a@example.com')->count(),
            'the fixture is meant to hold two rows for this address',
        );

        $this->assertSame(6, (int) DB::table('suppressions')->count());
        $this->assertSame(3, (new Blocked)->value($this->frage()), 'and five addresses on the list resolve to three still blocked');
    }

    /**
     * An address blocked and released inside the period is in neither end of it.
     *
     * b@ is exactly that: two events, both counted next door, and no presence
     * in the closing figure. A holding that counted arrivals would say four.
     */
    #[Test]
    public function an_address_released_inside_the_period_is_gone_from_the_closing_holding(): void
    {
        $this->fixture();

        $this->assertSame(3, (new Blocked)->value($this->frage()));

        // Released a week later instead, and it is on the list at the close.
        Suppression::query()
            ->where('email_normalized', 'b@example.com')
            ->update(['released_at' => '2026-08-25 10:00:00']);

        $this->assertSame(4, (new Blocked)->value($this->frage()), 'and now b@ is on the list at the close as well');
    }

    /** A list nobody is on has no series, not a row of zeroes. */
    #[Test]
    public function a_period_before_anything_existed_has_no_holding_series(): void
    {
        $this->fixture();

        $leer = new MetricQuery(Period::between(
            Carbon::parse('2025-01-01')->startOfDay(),
            Carbon::parse('2025-01-31')->endOfDay(),
        ));

        $this->assertSame(0, (new Blocked)->value($leer));
        $this->assertSame([], (new Blocked)->series($leer), 'an omitted bucket is filled with zero, which is the right level here');
        $this->assertSame(0, (new Events)->value($leer));
        $this->assertSame([], (new Events)->series($leer));
    }

    /** The grain comes from the question, not from the period. */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $this->assertSame(['2026-08' => 10], (new Events)->series($this->frage(MetricQuery::BUCKET_MONTH)));
        $this->assertSame(['2026-08' => 3], (new Blocked)->series($this->frage(MetricQuery::BUCKET_MONTH)));
    }

    /**
     * A period with no end still stops at today.
     *
     * `expires_at` is routinely in the future here — e@ is blocked until the end
     * of September. Left unbounded, "all time" would report that block as having
     * already lapsed, which is the one period nobody checks by hand.
     */
    #[Test]
    public function a_period_with_no_end_still_stops_at_today(): void
    {
        $this->fixture();

        $alles = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(11, (new Events)->value($alles), 'the July event joins the ten');
        $this->assertSame(3, (new Blocked)->value($alles), 'and e@, blocked until September, is still blocked');
    }

    // -- The last fraction of the last second --------------------------------

    /**
     * An event stamped at 23:59:59.500 on the closing day is inside the period.
     *
     * This is the defect that made these metrics stop writing their own window.
     * The period's `to` is 23:59:59.999999, a binding formats a date as
     * `Y-m-d H:i:s` and drops the fraction, and so a `<=` comparison against a
     * column that keeps milliseconds threw away every row in the final second —
     * silently, and only where the fraction survives, which is why a green suite
     * never showed it.
     * {@see TableMetric::inPeriod()} compares
     * `< midnight` instead, and midnight is the same instant at every precision.
     *
     * Written straight to the table because the model's `datetime` cast formats
     * to whole seconds: through it the case cannot be stated at all.
     */
    #[Test]
    public function an_event_in_the_last_fraction_of_the_final_second_is_counted(): void
    {
        DB::table('suppression_events')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => Reasons::GLOBAL_BRAND_ID,
                'email_normalized' => 'mittag@example.com',
                'event_type' => SuppressionEvent::TYPE_SUPPRESSED,
                'reason' => Reasons::HARD_BOUNCE,
                'source' => 'scaleway',
                'occurred_at' => '2026-08-19 12:00:00',
                'created_at' => '2026-08-19 12:00:00',
                'updated_at' => '2026-08-19 12:00:00',
            ],
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => Reasons::GLOBAL_BRAND_ID,
                'email_normalized' => 'kurz-vor-zwoelf@example.com',
                'event_type' => SuppressionEvent::TYPE_SUPPRESSED,
                'reason' => Reasons::HARD_BOUNCE,
                'source' => 'scaleway',
                'occurred_at' => '2026-08-19 23:59:59.500',
                'created_at' => '2026-08-19 23:59:59',
                'updated_at' => '2026-08-19 23:59:59',
            ],
        ]);

        $tag = new MetricQuery(Period::between(
            Carbon::parse('2026-08-19')->startOfDay(),
            Carbon::parse('2026-08-19')->endOfDay(),
        ));

        $this->assertSame(2, (new Events)->value($tag), 'the event half a second before midnight is inside the day');
        $this->assertSame(['2026-08-19' => 2], (new Events)->series($tag), "and it is in that day's column, not the next one");
    }

    /**
     * The holding had the same defect, and it did not arrive by inheritance.
     *
     * Its neighbour goes through {@see TableMetric::inPeriod()} and was
     * repaired there. This one cannot: a holding is asked *of an instant*, so
     * it writes its own comparisons — and they were the inclusive kind. Every
     * instant it asks about is the end of something, and every one of those is
     * 23:59:59.999999, so an address blocked in that last fraction was
     * reported by the event figure and missing from the holding beside it.
     */
    #[Test]
    public function an_address_blocked_in_the_last_fraction_of_the_final_second_is_on_the_list_at_the_close(): void
    {
        $this->rawBlock('kurz-vor-zwoelf@example.com', suppressedAt: '2026-08-19 23:59:59.500');

        $tag = new MetricQuery(Period::between(
            Carbon::parse('2026-08-19')->startOfDay(),
            Carbon::parse('2026-08-19')->endOfDay(),
        ));

        $this->assertSame(1, (new Blocked)->value($tag), 'blocked half a second before midnight is blocked at the close of that day');
        $this->assertSame(['2026-08-19' => 1], (new Blocked)->series($tag), "and the day's column has to agree with the headline");
    }

    /**
     * And the same second at the other end: an address that leaves in it has left.
     *
     * The mirror of the case above, and the one that reads as an over-count:
     * compared inclusively, an address released — or a temporary block lapsing
     * — in the final fraction was still on the list at the close. Two rows,
     * because the two ways off the list are two separate conditions and a
     * repair to one is not a repair to the other.
     */
    #[Test]
    public function an_address_leaving_in_the_last_fraction_of_the_final_second_is_off_the_list_at_the_close(): void
    {
        $this->rawBlock('freigegeben@example.com', suppressedAt: '2026-08-18 09:00:00', releasedAt: '2026-08-19 23:59:59.500');
        $this->rawBlock('abgelaufen@example.com', suppressedAt: '2026-08-18 09:00:00', expiresAt: '2026-08-19 23:59:59.500');

        $fenster = new MetricQuery(Period::between(
            Carbon::parse('2026-08-18')->startOfDay(),
            Carbon::parse('2026-08-19')->endOfDay(),
        ));

        $this->assertSame(0, (new Blocked)->value($fenster), 'neither address may still be on the list at the close');
        $this->assertSame(
            ['2026-08-18' => 2],
            (new Blocked)->series($fenster),
            'both are on the list through the 18th and off it by the end of the 19th, which is a level of zero and therefore no column',
        );
    }

    /**
     * One row written straight to the table, so a sub-second timestamp survives.
     *
     * {@see block()} goes through the model, which is the right way to build a
     * fixture and the wrong way to state these two cases: the `datetime` cast
     * formats to whole seconds and the fraction would be gone before it reached
     * the database.
     */
    protected function rawBlock(
        string $email,
        string $suppressedAt,
        ?string $expiresAt = null,
        ?string $releasedAt = null,
        int $brandId = Reasons::GLOBAL_BRAND_ID,
    ): void {
        DB::table('suppressions')->insert([
            'uuid' => (string) Str::uuid(),
            'brand_id' => $brandId,
            'email_normalized' => $email,
            'reason' => Reasons::HARD_BOUNCE,
            'source' => 'scaleway',
            'suppressed_at' => $suppressedAt,
            'expires_at' => $expiresAt,
            'released_at' => $releasedAt,
            'released_by' => $releasedAt === null ? null : 'adrian',
            'release_reason' => $releasedAt === null ? null : 'Der Empfänger hat sich selbst wieder angemeldet.',
            'created_at' => $suppressedAt,
            'updated_at' => $suppressedAt,
        ]);
    }

    // -- Across brands --------------------------------------------------------

    /**
     * The figures are across every brand, on purpose — and they say so.
     *
     * b@ is a complaint recorded by brand 3 — a block and a release — and both
     * of its events are in the count. The decision was re-examined when the base
     * class grew a brand filter and it stands, for a reason that is about these
     * two tables rather than about reports in general: `brand_id = 0` here means
     * *every* brand, which is how a hard bounce is recorded, and the filter on
     * offer compares for equality. Applied, it would drop exactly the rows that
     * block the most sends. See `SuppressionMetric::brandColumn()`.
     */
    #[Test]
    public function the_figures_do_not_stop_at_a_brand_boundary(): void
    {
        $this->fixture();

        $this->assertSame(2, (int) DB::table('suppression_events')->where('brand_id', 3)->count());
        $this->assertSame(10, (new Events)->value($this->frage()), 'and both of them are in the total');

        // And with brand isolation switched on, which is the case where a
        // silently narrowed figure would have been wrong: the total is the
        // same, because the global rows are the point of the table.
        $this->makeBrand('zwei');
        $this->enableMultiBrand();

        $this->assertSame(10, (new Events)->value($this->frage()), 'a global hard bounce belongs to every brand, so nothing is narrowed away');
        $this->assertSame(3, (new Blocked)->value($this->frage()), 'a@, d@ and e@ — the same three the single-brand reading gives');
    }

    /**
     * A tile that counts more than the one beside it has to say so on the screen.
     *
     * Not in a docblock — in the description a reader sees. Every other figure
     * in this family stops at the brand boundary, and a screen where one number
     * counts one customer and the next counts four, with nothing on either
     * saying which, is worse than a screen that knows no brands at all. Both
     * languages, because the reader may only have one of them.
     */
    #[Test]
    public function both_figures_say_on_screen_that_they_span_every_brand(): void
    {
        foreach ([new Events, new Blocked] as $metrik) {
            $this->assertStringContainsString(
                'every brand',
                (string) $metrik->description(),
                $metrik::class.' counts across brands without telling the reader.',
            );
        }

        $deutsch = require __DIR__.'/../../resources/lang/de/insights.php';

        foreach (['metric_events_description', 'metric_active_description'] as $schluessel) {
            $this->assertStringContainsString('alle Marken', $deutsch[$schluessel], $schluessel.' says it in English only.');
        }
    }

    // -- The wiring ----------------------------------------------------------

    /**
     * The provider hands both to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * two metric objects on a request that renders neither.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'suppression.events' => Events::class,
            'suppression.active' => Blocked::class,
        ], $this->insights->registered);
    }

    // -- Machinery ------------------------------------------------------------

    /** @return array<string, string> key => label */
    protected function beschriftet(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$row['key'] ?? ''] = $row['label'];
        }

        return $out;
    }
}
