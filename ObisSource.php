<?php

namespace Omnilab\Obis;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\OccurrenceQuery;
use Omnilab\Source\Page;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OBIS, the Ocean Biodiversity Information System (IOC-UNESCO), through its
 * API v3 (api.obis.org/v3): a marine taxon by its WoRMS AphiaID or its
 * name - OBIS's taxonomy is WoRMS' - with its rank, status, classification,
 * vernacular names, NCBI id, habitat flags and how many records OBIS holds
 * of it; and the occurrences of a place and a period (occurrences()), each
 * with its time, its place, its depth, the sea surface temperature and
 * salinity OBIS gives its place, its taxon related; a record by its UUID.
 *
 * No key, no published rate; OBIS pages a query by the id of its last
 * record (after), 10,000 a page at most.
 */
final class ObisSource extends HttpSource
{
    public const BASE_URI = 'https://api.obis.org/v3/';

    /** The most records one page holds (OBIS's 400 beyond). */
    public const MAX_PER_PAGE = 10000;

    /** OBIS's own filters: the depth in metres (min, max), a dataset's UUID, Red List species only. */
    public const MIN_DEPTH = 'min_depth';
    public const MAX_DEPTH = 'max_depth';
    public const DATASET = 'dataset';
    public const RED_LIST = 'red_list';

    /** The ranks OBIS classifies by, from the root down, each with its id (kingdomid...). */
    private const RANKS = ['kingdom', 'subkingdom', 'infrakingdom', 'phylum', 'subphylum', 'infraphylum', 'parvphylum', 'gigaclass', 'megaclass', 'superclass', 'class', 'subclass', 'infraclass', 'superorder', 'order', 'suborder', 'infraorder', 'parvorder', 'superfamily', 'family', 'subfamily', 'tribe', 'genus', 'subgenus', 'species', 'subspecies'];

    /** The ranks given each a property (GBIF's and NCBI's keys). */
    private const MAIN_RANKS = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];

    /** What an occurrence page is asked of each record. */
    private const FIELDS = 'id,occurrenceID,scientificName,scientificNameAuthorship,aphiaID,taxonRank,vernacularName,eventDate,date_start,date_end,decimalLatitude,decimalLongitude,coordinateUncertaintyInMeters,depth,minimumDepthInMeters,maximumDepthInMeters,basisOfRecord,country,locality,waterBody,recordedBy,institutionCode,datasetName,dataset_id,license,individualCount,sst,sss,bathymetry,shoredistance,flags,kingdom,phylum,class,order,family,genus';

    /**
     * @param bool  $statistics entity() asks for the taxon's records too (a second call; up to 8 s for a kingdom)
     * @param float $radius     the circle around a Point, in kilometres, for occurrences() when the query gives none
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly bool $statistics = true,
        private readonly float $radius = 10.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'obis';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::OCCURRENCES, Capability::AREA, Capability::CROSS_REFERENCES];
    }

    /**
     * A taxon by its AphiaID (137094, worms:137094, its LSID, its WoRMS page)
     * or its scientific name as OBIS writes it (an unaccepted name gives its
     * own record, the accepted taxon related); a record by its UUID
     * (obis.occurrence:...). One call, two with the taxon's statistics.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::WORMS, Scheme::OBIS_OCCURRENCE];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('obis', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::tryOf(Scheme::WORMS, $text) ?? Identifier::tryOf(Scheme::OBIS_OCCURRENCE, $text) ?? Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('obis', $id);
            }
            if (null === $identifier) {
                // A name: letters, spaces, a hyphen or a dot (OBIS reads it in its path).
                return '' === $text || !preg_match('~^[\p{L}][\p{L} .()-]*$~u', $text) ? null : $this->taxon(rawurlencode($text));
            }
        }
        if (Scheme::OBIS_OCCURRENCE === $identifier->scheme) {
            $record = $this->getJson('occurrence/'.$identifier->value)['results'][0] ?? null;

            return \is_array($record) && isset($record['id']) ? $this->toOccurrence($record) : null;
        }

        return $this->taxon($identifier->value);
    }

    /**
     * The records of the place and the period, each an entity with its time
     * and its place, its taxon related; present records only (OBIS leaves
     * out absences and the records its quality control dropped).
     *
     * A BBox is the box (across the antimeridian, its east past 180°); a
     * Point, the circle of the query's radius (10 km by default) as a
     * polygon of 64 sides; no place, the ocean. The period: whole days
     * (OBIS takes no time), on the records' start and end. Filters:
     * OccurrenceQuery::TAXON (an AphiaID, an Identifier of worms - its
     * subordinate taxa included -, or a scientific name), min_depth and
     * max_depth (metres), dataset (an OBIS dataset's UUID), red_list (true:
     * the IUCN Red List's species). No sort: OBIS's order, by id, the next
     * page after the last one. 10,000 a page at most; the total is OBIS's.
     */
    public function occurrences(OccurrenceQuery $query): Page
    {
        $filters = $this->filters($query, OccurrenceQuery::TAXON, self::MIN_DEPTH, self::MAX_DEPTH, self::DATASET, self::RED_LIST);
        if (null !== $query->sort) {
            throw NotSupportedException::operation('obis', \sprintf('sort occurrences by "%s" (OBIS answers its own order, by id)', $query->sort));
        }
        $size = max(1, min(self::MAX_PER_PAGE - 1, $query->limit));
        $after = null;
        if (null !== $query->cursor) {
            $after = preg_match('~^after:([0-9a-f-]{36})$~', $query->cursor, $m) ? $m[1] : throw new \InvalidArgumentException(\sprintf('"%s" is no cursor of this source.', $query->cursor));
        }
        $parameters = $this->taxonFilter($filters[OccurrenceQuery::TAXON] ?? null) + [
            'geometry' => $this->geometry($query),
            'startdate' => self::day($query->from, 'from'),
            'enddate' => self::day($query->to, 'to'),
            'startdepth' => isset($filters[self::MIN_DEPTH]) ? (string) (int) floor((float) $filters[self::MIN_DEPTH]) : null,
            'enddepth' => isset($filters[self::MAX_DEPTH]) ? (string) (int) ceil((float) $filters[self::MAX_DEPTH]) : null,
            'datasetid' => isset($filters[self::DATASET]) ? (string) $filters[self::DATASET] : null,
            'redlist' => isset($filters[self::RED_LIST]) ? (bool) $filters[self::RED_LIST] : null,
            'fields' => self::FIELDS,
            'after' => $after,
            // One more than asked: whether a page follows.
            'size' => $size + 1,
        ];
        $data = $this->getJson('occurrence', $parameters) ?? [];
        $records = array_values(array_filter($data['results'] ?? [], static fn (mixed $r) => \is_array($r) && isset($r['id'])));
        $more = \count($records) > $size;
        $records = \array_slice($records, 0, $size);
        $entities = array_values(array_filter(array_map($this->toOccurrence(...), $records), static fn (Entity $e) => null !== $e->time && null !== $e->place));

        return new Page($entities, $more ? 'after:'.end($records)['id'] : null, isset($data['total']) ? (int) $data['total'] : null);
    }

    private function taxon(string $path): ?Entity
    {
        $taxon = $this->getJson('taxon/'.$path)['results'][0] ?? null;
        if (!\is_array($taxon) || !isset($taxon['taxonID'], $taxon['scientificName'])) {
            return null;
        }
        $statistics = $this->statistics ? $this->getJson('statistics', ['taxonid' => (string) $taxon['taxonID']]) : null;

        return $this->toTaxon($taxon, $statistics);
    }

    /** @return array<string, mixed> */
    private function taxonFilter(mixed $taxon): array
    {
        if (null === $taxon) {
            return [];
        }
        if ($taxon instanceof Identifier) {
            return Scheme::WORMS === $taxon->scheme ? ['taxonid' => $taxon->value] : throw NotSupportedException::identifier('obis', (string) $taxon, 'filter occurrences by');
        }
        if (!\is_string($taxon) && !\is_int($taxon)) {
            throw new \InvalidArgumentException(\sprintf('A taxon is an Identifier of worms, an AphiaID or a name, not %s.', get_debug_type($taxon)));
        }
        $text = trim((string) $taxon);
        $identifier = Identifier::tryOf(Scheme::WORMS, $text) ?? Identifier::parse($text);
        if (null !== $identifier && Scheme::WORMS !== $identifier->scheme) {
            throw NotSupportedException::identifier('obis', $text, 'filter occurrences by');
        }

        return null === $identifier ? ['scientificname' => $text] : ['taxonid' => $identifier->value];
    }

    /** A box or a circle as OBIS's WKT; null for the whole ocean. */
    private function geometry(OccurrenceQuery $query): ?string
    {
        $place = $query->place;
        if (null === $place) {
            return null;
        }
        $ring = [];
        if ($place instanceof BBox) {
            // Across the antimeridian, the east past 180°: OBIS reads it so (checked on 2026-10-15 against its two halves).
            $east = $place->east < $place->west ? $place->east + 360 : $place->east;
            $ring = [[$place->west, $place->south], [$east, $place->south], [$east, $place->north], [$place->west, $place->north]];
        } else {
            $radius = $query->radius ?? $this->radius;
            $lat = deg2rad($place->latitude);
            $lon = deg2rad($place->longitude);
            $d = $radius / 6371.0088;
            for ($i = 0; $i < 64; ++$i) {
                $bearing = 2 * M_PI * $i / 64;
                $lat2 = asin(sin($lat) * cos($d) + cos($lat) * sin($d) * cos($bearing));
                $lon2 = $lon + atan2(sin($bearing) * sin($d) * cos($lat), cos($d) - sin($lat) * sin($lat2));
                $ring[] = [rad2deg($lon2), max(-90.0, min(90.0, rad2deg($lat2)))];
            }
        }
        $ring[] = $ring[0];

        return 'POLYGON(('.implode(',', array_map(static fn (array $p) => self::number($p[0]).' '.self::number($p[1]), $ring)).'))';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');
    }

    /** A bound as OBIS reads it: a day; a bound with a time of day is refused (OBIS answers 500 to it). */
    private static function day(?\DateTimeInterface $bound, string $which): ?string
    {
        if (null === $bound) {
            return null;
        }
        if ('00:00:00' !== $bound->format('H:i:s')) {
            throw NotSupportedException::operation('obis', \sprintf('take a time of day (%s %s): it reads whole days', $which, $bound->format(\DATE_ATOM)));
        }

        return $bound->format('Y-m-d');
    }

    /**
     * @param array<string, mixed>      $t a taxon of OBIS
     * @param array<string, mixed>|null $s its statistics
     */
    private function toTaxon(array $t, ?array $s): Entity
    {
        $id = (string) $t['taxonID'];
        $name = self::text((string) $t['scientificName']) ?? $id;
        $rank = null !== ($r = self::text($t['taxonRank'] ?? null)) ? strtolower($r) : null;
        $authority = self::text($t['scientificNameAuthorship'] ?? null);
        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'obis');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', $authority);
        $add('scientific_name', 'Scientific name', null === $authority ? $name : $name.' '.$authority);
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('taxonomic_status', 'Taxonomic status', self::text($t['taxonomicStatus'] ?? null));
        $accepted = self::text($t['acceptedNameUsage'] ?? null);
        $add('accepted_name', 'Accepted name', null !== $accepted && $accepted !== $name ? $accepted : null);
        $lineage = [];
        $parent = null;
        foreach (self::RANKS as $level) {
            $value = self::text($t[$level] ?? null);
            if (null === $value || $level === $rank) {
                continue;
            }
            $lineage[] = $value;
            if (isset($t[$level.'id'])) {
                $parent = $t[$level.'id'];
            }
            if (\in_array($level, self::MAIN_RANKS, true)) {
                $add($level, ucfirst($level), $value);
            }
        }
        $add('lineage', 'Lineage', $lineage ? implode(' > ', $lineage) : null);
        foreach (['is_marine' => 'Marine', 'is_brackish' => 'Brackish', 'is_freshwater' => 'Freshwater', 'is_terrestrial' => 'Terrestrial'] as $key => $label) {
            $add(substr($key, 3), $label, isset($t[$key]) ? (bool) $t[$key] : null);
        }
        $vernaculars = array_values(array_filter(array_map(static fn (mixed $v) => \is_string($v) ? self::text($v) : null, (array) ($t['vernacularNames'] ?? []))));
        $add('common_name', 'Common name', $vernaculars[0] ?? null);
        if (\is_array($s)) {
            $add('records', 'Records (in OBIS)', is_numeric($s['records'] ?? null) ? (int) $s['records'] : null);
            $add('datasets', 'Datasets (in OBIS)', is_numeric($s['datasets'] ?? null) ? (int) $s['datasets'] : null);
            $years = \is_array($s['yearrange'] ?? null) ? $s['yearrange'] : [];
            $add('first_year', 'First year recorded', is_numeric($years[0] ?? null) ? (int) $years[0] : null);
            $add('last_year', 'Last year recorded', is_numeric($years[1] ?? null) ? (int) $years[1] : null);
        }
        $acceptedId = isset($t['acceptedNameUsageID']) && (string) $t['acceptedNameUsageID'] !== $id ? Identifier::tryOf(Scheme::WORMS, $t['acceptedNameUsageID']) : null;

        return new Entity(
            id: Identifier::of(Scheme::WORMS, $id),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: $vernaculars,
            properties: $properties,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::NCBI_TAXON, $t['ncbi_id'] ?? null)),
            url: 'https://obis.org/taxon/'.$id,
            source: 'obis',
            related: array_filter([
                'accepted' => Identifiers::of($acceptedId),
                'parent' => Identifiers::of(null === $parent || (string) $parent === $id ? null : Identifier::tryOf(Scheme::WORMS, $parent)),
            ], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** @param array<string, mixed> $o a record of OBIS */
    private function toOccurrence(array $o): Entity
    {
        $id = strtolower((string) $o['id']);
        $name = self::text($o['scientificName'] ?? null) ?? 'Unknown';
        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'obis');
            }
        };
        $metres = static fn (mixed $v): ?Quantity => is_numeric($v) ? new Quantity(0 + $v, 'm') : null;
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', self::text($o['scientificNameAuthorship'] ?? null));
        $add('taxon_rank', 'Taxon rank', null !== ($r = self::text($o['taxonRank'] ?? null)) ? strtolower($r) : null);
        $add('common_name', 'Common name', self::text($o['vernacularName'] ?? null));
        foreach (self::MAIN_RANKS as $level) {
            $add($level, ucfirst($level), self::text($o[$level] ?? null));
        }
        $add('basis_of_record', 'Basis of record', self::text($o['basisOfRecord'] ?? null));
        $add('event_date', 'Event date (as published)', self::text($o['eventDate'] ?? null));
        $depth = $o['depth'] ?? null;
        if (!is_numeric($depth) && is_numeric($o['minimumDepthInMeters'] ?? null) && is_numeric($o['maximumDepthInMeters'] ?? null)) {
            $depth = ($o['minimumDepthInMeters'] + $o['maximumDepthInMeters']) / 2;
        }
        $add('depth', 'Depth', $metres($depth));
        $add('min_depth', 'Minimum depth', $metres($o['minimumDepthInMeters'] ?? null));
        $add('max_depth', 'Maximum depth', $metres($o['maximumDepthInMeters'] ?? null));
        $add('coordinate_uncertainty', 'Coordinate uncertainty', $metres($o['coordinateUncertaintyInMeters'] ?? null));
        $add('individual_count', 'Individuals', is_numeric($o['individualCount'] ?? null) ? (int) $o['individualCount'] : null);
        $add('country', 'Country', self::text($o['country'] ?? null));
        $add('locality', 'Locality', self::text($o['locality'] ?? null));
        $add('water_body', 'Water body', self::text($o['waterBody'] ?? null));
        $add('recorded_by', 'Recorded by', self::text($o['recordedBy'] ?? null));
        $add('institution', 'Institution', self::text($o['institutionCode'] ?? null));
        $add('dataset', 'Dataset', self::text($o['datasetName'] ?? null));
        $add('dataset_id', 'Dataset (OBIS id)', self::text($o['dataset_id'] ?? null));
        $add('license', 'Licence', self::text($o['license'] ?? null));
        $add('occurrence_id', 'Occurrence id (its publisher\'s)', self::text($o['occurrenceID'] ?? null));
        // What OBIS's quality control gives the place: the sea surface's temperature and salinity, the depth of the sea floor, the distance to the shore.
        $add('sea_surface_temperature', 'Sea surface temperature (OBIS, at the place)', is_numeric($o['sst'] ?? null) ? new Quantity(0 + $o['sst'], '°C') : null);
        $add('sea_surface_salinity', 'Sea surface salinity (OBIS, at the place)', is_numeric($o['sss'] ?? null) ? new Quantity(0 + $o['sss'], 'PSU') : null);
        $add('bathymetry', 'Sea floor depth (OBIS, at the place; negative on land)', $metres($o['bathymetry'] ?? null));
        $add('shore_distance', 'Distance to the shore (OBIS; negative on land)', $metres($o['shoredistance'] ?? null));
        $add('flags', 'Quality flags', \is_array($o['flags'] ?? null) && $o['flags'] ? implode(', ', array_map('strval', $o['flags'])) : null);

        $place = is_numeric($o['decimalLatitude'] ?? null) && is_numeric($o['decimalLongitude'] ?? null) && abs((float) $o['decimalLatitude']) <= 90 && abs((float) $o['decimalLongitude']) <= 180
            ? new Point((float) $o['decimalLatitude'], (float) $o['decimalLongitude']) : null;
        $time = self::when($o);
        // A record iNaturalist published is that observation (its occurrenceID is the observation's page).
        $inaturalist = \is_string($o['occurrenceID'] ?? null) && preg_match('~^https?://(?:www\.)?inaturalist\.org/observations/(\d+)$~', $o['occurrenceID'], $m) ? Identifier::tryOf(Scheme::INATURALIST_OBSERVATION, $m[1]) : null;
        $where = self::text($o['locality'] ?? null) ?? self::text($o['waterBody'] ?? null);

        return new Entity(
            id: Identifier::of(Scheme::OBIS_OCCURRENCE, $id),
            label: $name.' - '.implode(', ', array_filter([$where, $time?->format('Y-m-d')])),
            domain: Domain::TAXONOMY,
            kind: 'occurrence',
            properties: $properties,
            identifiers: Identifiers::of($inaturalist),
            url: 'https://api.obis.org/v3/occurrence/'.$id,
            time: $time,
            place: $place,
            source: 'obis',
            related: array_filter(['taxon' => Identifiers::of(Identifier::tryOf(Scheme::WORMS, $o['aphiaID'] ?? null))], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /**
     * When: the start of its eventDate when written with its offset; else
     * its first day (OBIS's date_start), midnight UTC - a time without an
     * offset is no instant.
     *
     * @param array<string, mixed> $o
     */
    private static function when(array $o): ?\DateTimeImmutable
    {
        $date = \is_string($o['eventDate'] ?? null) ? explode('/', $o['eventDate'])[0] : '';
        if (preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})$~', $date) && null !== ($time = self::time($date))) {
            return $time;
        }
        if (is_numeric($o['date_start'] ?? null)) {
            return (new \DateTimeImmutable('@'.intdiv((int) $o['date_start'], 1000)))->setTimezone(new \DateTimeZone('UTC'));
        }

        return null;
    }
}
