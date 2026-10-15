<?php

namespace Omnilab\Obis\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Obis\ObisSource;
use Omnilab\Obis\ObisSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.obis.org on 2026-10-15 (Tests/Fixtures/index.json
 * names each one's address): the common dolphin (AphiaID 137094) by id and
 * by name with its statistics, an unaccepted name (Delphinus albimanus), an
 * unknown id and name; the common dolphins of the Bay of Biscay in 2023 in
 * two pages; a record by its UUID; the records within 30 km of Marseille in
 * 2024; a box across the antimeridian; sea cucumbers between 3,000 and
 * 4,000 m.
 */
final class ObisSourceTest extends TestCase
{
    /** @var list<string> the URLs asked, decoded */
    private array $urls = [];

    private function source(array $options = []): SourceInterface
    {
        $fixtures = __DIR__.'/Fixtures';
        $index = json_decode((string) file_get_contents($fixtures.'/index.json'), true);
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($fixtures, $index): MockResponse {
            $this->urls[] = rawurldecode($url);
            $name = self::fixture($method, $url, $o['body'] ?? null);
            if (!isset($index[$name])) {
                self::fail('No recorded answer for '.rawurldecode($url));
            }

            return new MockResponse((string) file_get_contents($fixtures.'/'.$name), ['http_code' => $index[$name]['status']]);
        });

        return (new ObisSourceFactory($http))->create($options + ['mailto' => 'support@glitchr.io']);
    }

    /** The name the recorder gave an answer: the address made readable, and a hash of the call. */
    private static function fixture(string $method, string $url, ?string $body): string
    {
        $parts = parse_url($url);
        $readable = strtolower(trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', rawurldecode(($parts['path'] ?? '').'-'.($parts['query'] ?? ''))), '-'));

        return substr($readable, 0, 70).'-'.substr(md5($method.' '.$url.' '.$body), 0, 8).'.json';
    }

    private function biscay(): OccurrenceQuery
    {
        return new OccurrenceQuery(new BBox(-10, 43, -1, 48), new \DateTimeImmutable('2023-01-01'), new \DateTimeImmutable('2023-12-31'), [OccurrenceQuery::TAXON => '137094'], limit: 20);
    }

    public function testCapabilities(): void
    {
        $source = $this->source();
        self::assertSame('obis', $source->getName());
        self::assertSame([Capability::ENTITY, Capability::OCCURRENCES, Capability::AREA, Capability::CROSS_REFERENCES], $source->capabilities());
    }

    public function testTheCommonDolphinByItsAphiaId(): void
    {
        $dolphin = $this->source()->entity('137094');

        self::assertSame(['https://api.obis.org/v3/taxon/137094', 'https://api.obis.org/v3/statistics?taxonid=137094'], $this->urls);
        self::assertSame(['worms:137094', 'Delphinus delphis', Domain::TAXONOMY, 'species'], [(string) $dolphin->id, $dolphin->label, $dolphin->domain, $dolphin->kind]);
        self::assertSame(['Linnaeus, 1758', 'Delphinus delphis Linnaeus, 1758', 'accepted'], [$dolphin->value('taxon_authority'), $dolphin->value('scientific_name'), $dolphin->value('taxonomic_status')]);
        self::assertSame(['Animalia', 'Chordata', 'Mammalia', 'Cetartiodactyla', 'Delphinidae', 'Delphinus'], [$dolphin->value('kingdom'), $dolphin->value('phylum'), $dolphin->value('class'), $dolphin->value('order'), $dolphin->value('family'), $dolphin->value('genus')]);
        self::assertStringEndsWith('Odontoceti > Delphinidae > Delphinus', $dolphin->value('lineage'));
        self::assertSame([true, false], [$dolphin->value('marine'), $dolphin->value('terrestrial')]);
        self::assertSame([121709, 374, 1758, 2026], [$dolphin->value('records'), $dolphin->value('datasets'), $dolphin->value('first_year'), $dolphin->value('last_year')]);
        self::assertSame('common dolphin', $dolphin->value('common_name'));
        self::assertContains('short-beaked common dolphin', $dolphin->synonyms);
        self::assertSame(['worms:137094', 'ncbi.taxon:9728'], $dolphin->identifiers()->keys(), 'OBIS gives the NCBI taxon: the Collector follows it');
        self::assertSame(['worms:137015'], $dolphin->related('parent')->keys(), 'Delphinus');
        self::assertSame('https://obis.org/taxon/137094', $dolphin->url);
        self::assertSame('obis', $dolphin->property('records')->source);
    }

    public function testByItsNameItsLsidOrItsIdentifier(): void
    {
        foreach (['Delphinus delphis', 'urn:lsid:marinespecies.org:taxname:137094', Identifier::of(Scheme::WORMS, '137094'), 'https://www.marinespecies.org/aphia.php?p=taxdetails&id=137094'] as $id) {
            self::assertSame('worms:137094', (string) $this->source()->entity($id)?->id, (string) $id);
        }
        self::assertContains('https://api.obis.org/v3/taxon/Delphinus delphis', $this->urls);
    }

    public function testAnUnacceptedNameItsAcceptedTaxonRelated(): void
    {
        $name = $this->source()->entity('Delphinus albimanus');

        self::assertSame(['worms:383799', 'unaccepted', 'Delphinus delphis'], [(string) $name->id, $name->value('taxonomic_status'), $name->value('accepted_name')]);
        self::assertSame(['worms:137094'], $name->related('accepted')->keys());
    }

    public function testUnknownIsNullAndOtherSchemesAreRefusedBeforeAnyCall(): void
    {
        self::assertNull($this->source()->entity('999999999'));
        self::assertNull($this->source()->entity('Nothingus nowhereus'));
        self::assertNull($this->source()->entity(''));
        $this->urls = [];
        foreach ([Identifier::of(Scheme::GBIF, '4CGXP'), 'Q140', 'itis:180438', Identifier::of(Scheme::NCBI_TAXON, '9728')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testTheOccurrencesOfABoxAndAYearInTwoPages(): void
    {
        $source = $this->source();
        $first = $source->occurrences($this->biscay());

        self::assertStringContainsString('taxonid=137094&geometry=POLYGON((-10 43,-1 43,-1 48,-10 48,-10 43))&startdate=2023-01-01&enddate=2023-12-31', $this->urls[0]);
        self::assertStringEndsWith('&size=21', $this->urls[0], 'one more than asked: whether a page follows');
        self::assertSame([30, 20], [$first->total, \count($first)]);
        self::assertSame('after:bb0bdf40-2d28-411d-b735-d63d4c467ece', $first->next);
        $second = $source->occurrences($this->biscay()->with(['cursor' => $first->next]));
        self::assertStringContainsString('&after=bb0bdf40-2d28-411d-b735-d63d4c467ece&size=21', $this->urls[1]);
        self::assertCount(10, $second);
        self::assertNull($second->next);
        foreach ([...$first->items, ...$second->items] as $occurrence) {
            self::assertTrue((new BBox(-10, 43, -1, 48))->contains($occurrence->place));
            self::assertSame('2023', $occurrence->time->format('Y'));
            self::assertSame(['worms:137094'], $occurrence->related('taxon')->keys());
        }
        $one = $first->first();
        self::assertSame(['obis.occurrence:00adc3f8-cace-47b2-89e4-2070e6956b38', 'occurrence', '2023-08-16T05:30:00+00:00'], [(string) $one->id, $one->kind, $one->time->format(\DATE_ATOM)]);
        self::assertSame(['inaturalist.observation:179511451'], $one->identifiers->keys(), 'published by iNaturalist: its observation');
        self::assertSame(['HumanObservation', 'iNaturalist research-grade observations'], [$one->value('basis_of_record'), $one->value('dataset')]);
        self::assertSame('2023-11-23T14:50:52+01:00', $first->items[1]->time->format(\DATE_ATOM), 'at its offset');
    }

    public function testARecordByItsUuid(): void
    {
        $record = $this->source()->entity('obis.occurrence:00007235-5211-4633-98BB-0F8F9AD53E0F');

        self::assertSame(['https://api.obis.org/v3/occurrence/00007235-5211-4633-98bb-0f8f9ad53e0f'], $this->urls);
        self::assertSame('2000-02-19T00:00:00+00:00', $record->time->format(\DATE_ATOM), 'a day without a time: its midnight UTC');
        self::assertEquals(new Point(49.18, -1.601), $record->place);
        self::assertEquals(new Quantity(13.2, '°C'), $record->value('sea_surface_temperature'));
        self::assertEquals(new Quantity(34.6, 'PSU'), $record->value('sea_surface_salinity'));
        self::assertSame(['NO_DEPTH, ON_LAND', 'http://creativecommons.org/licenses/by-nc/4.0/', '2000-02-19'], [$record->value('flags'), $record->value('license'), $record->value('event_date')]);
    }

    public function testACircleIsAPolygonOfSixtyFourSides(): void
    {
        $page = $this->source()->occurrences(new OccurrenceQuery(new Point(43.3, 5.2), new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-12-31'), radius: 30, limit: 50));

        preg_match('~geometry=POLYGON\(\(([^)]*)\)\)~', $this->urls[0], $m);
        self::assertCount(65, explode(',', $m[1]), '64 corners, the first repeated');
        self::assertSame(12314, $page->total);
        self::assertCount(50, $page);
        foreach ($page as $occurrence) {
            $km = 6371.0088 * acos(min(1, sin(deg2rad(43.3)) * sin(deg2rad($occurrence->place->latitude)) + cos(deg2rad(43.3)) * cos(deg2rad($occurrence->place->latitude)) * cos(deg2rad($occurrence->place->longitude - 5.2))));
            self::assertLessThanOrEqual(30.0, $km);
        }
    }

    public function testABoxAcrossTheAntimeridianAndTheDepth(): void
    {
        $source = $this->source();
        $across = $source->occurrences(new OccurrenceQuery(new BBox(170, -20, -170, -15), limit: 5));
        self::assertStringContainsString('geometry=POLYGON((170 -20,190 -20,190 -15,170 -15,170 -20))', $this->urls[0], 'the east past 180°');
        self::assertSame(963372, $across->total);
        self::assertCount(4, $across, 'a record without a date is left out: every occurrence has its time');

        $deep = $source->occurrences(new OccurrenceQuery(filters: [ObisSource::MIN_DEPTH => 3000, ObisSource::MAX_DEPTH => 4000, OccurrenceQuery::TAXON => 'Holothuroidea'], limit: 10));
        self::assertStringContainsString('scientificname=Holothuroidea&startdepth=3000&enddepth=4000', $this->urls[1]);
        self::assertSame(3473, $deep->total);
        foreach ($deep as $occurrence) {
            self::assertGreaterThanOrEqual(2999, $occurrence->property('depth')?->number() ?? 3000);
        }
    }

    public function testWhatItDoesNotDoIsRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([
            $this->biscay()->with(['sort' => OccurrenceQuery::NEWEST]),
            $this->biscay()->with(['filters' => [OccurrenceQuery::COUNTRY => 'FR']]),
            $this->biscay()->with(['from' => new \DateTimeImmutable('2023-01-01 08:00')]),
            $this->biscay()->with(['filters' => [OccurrenceQuery::TAXON => Identifier::of(Scheme::GBIF, '4CGXP')]]),
        ] as $query) {
            try {
                $source->occurrences($query);
                self::fail('not refused');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }
}
