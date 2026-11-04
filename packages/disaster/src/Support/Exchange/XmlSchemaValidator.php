<?php

namespace Lumina\Disaster\Support\Exchange;

use DOMDocument;

/**
 * Structural validation for Lumina EDXL lite payloads, with optional XSD fixtures.
 *
 * Supported roots: HospitalStatus (HAVE), EDXLResourceMessage (RM), EDXLSitRep, EDXLDistribution (DE).
 * XSD fixtures (optional): packages/disaster/docs/fixtures/edxl/*.xsd
 */
final class XmlSchemaValidator
{
    public const FIXTURES_PATH = __DIR__.'/../../../docs/fixtures/edxl';

    /**
     * @return array{ok: bool, root: ?string, errors: list<string>, xsd: bool}
     */
    public static function validate(string $xml, bool $useXsd = true): array
    {
        try {
            $doc = XmlSupport::document($xml);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'root' => null, 'errors' => [$e->getMessage()], 'xsd' => false];
        }

        $root = $doc->documentElement?->localName;
        $errors = match ($root) {
            'HospitalStatus' => self::validateHave($doc),
            'EDXLResourceMessage' => self::validateRm($doc),
            'EDXLSitRep' => self::validateSitRep($doc),
            'EDXLDistribution' => self::validateDe($doc),
            default => ['Unsupported or missing EDXL root element.'],
        };

        $xsdApplied = false;
        if ($useXsd && $errors === [] && $root !== null) {
            $xsdErrors = self::validateAgainstFixture($doc, $root);
            if ($xsdErrors !== null) {
                $xsdApplied = true;
                $errors = array_merge($errors, $xsdErrors);
            }
        }

        return [
            'ok' => $errors === [],
            'root' => $root,
            'errors' => $errors,
            'xsd' => $xsdApplied,
        ];
    }

    /**
     * @return list<string>
     */
    protected static function validateHave(DOMDocument $doc): array
    {
        $errors = [];
        if (XmlSupport::firstText($doc, ['Organization', 'OrganizationID']) === null) {
            $errors[] = 'HAVE requires Organization/OrganizationID.';
        }

        $hospitals = XmlSupport::childrenNamed($doc->documentElement, 'Hospital');
        if ($hospitals === []) {
            $errors[] = 'HAVE requires at least one Hospital.';
        }

        foreach ($hospitals as $hospital) {
            if (XmlSupport::firstText($hospital, ['OrganizationInformation', 'OrganizationID']) === null) {
                $errors[] = 'Hospital requires OrganizationInformation/OrganizationID (facility_id).';
            }
            if (XmlSupport::firstText($hospital, ['HospitalBedCapacityStatus', 'BedCapacity', 'CapacityTotal']) === null) {
                $errors[] = 'Hospital requires BedCapacity/CapacityTotal.';
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    protected static function validateRm(DOMDocument $doc): array
    {
        $errors = [];
        foreach (['messageContentType', 'originatingMessageID', 'incidentID', 'organizationID'] as $field) {
            if (XmlSupport::firstText($doc, [$field]) === null) {
                $errors[] = "RM requires {$field}.";
            }
        }

        if (XmlSupport::firstText($doc, ['ResourceInformation', 'Quantity']) === null) {
            $errors[] = 'RM requires ResourceInformation/Quantity.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    protected static function validateSitRep(DOMDocument $doc): array
    {
        $errors = [];
        foreach (['incidentID', 'organizationID', 'preparedAt'] as $field) {
            if (XmlSupport::firstText($doc, [$field]) === null) {
                $errors[] = "SitRep requires {$field}.";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    protected static function validateDe(DOMDocument $doc): array
    {
        $errors = [];
        foreach (['distributionID', 'senderID', 'dateTimeSent', 'distributionType'] as $field) {
            if (XmlSupport::firstText($doc, [$field]) === null) {
                $errors[] = "DE requires {$field}.";
            }
        }

        try {
            $unwrapped = DistributionElement::unwrap($doc->saveXML() ?: '');
            $inner = XmlSupport::document($unwrapped['inner_xml']);
            $innerRoot = $inner->documentElement?->localName;
            if (! in_array($innerRoot, ['HospitalStatus', 'EDXLResourceMessage', 'EDXLSitRep'], true)) {
                $errors[] = 'DE xmlContent must be HAVE, RM, or SitRep.';
            }
        } catch (\Throwable $e) {
            $errors[] = 'DE content invalid: '.$e->getMessage();
        }

        return $errors;
    }

    /**
     * @return list<string>|null null when no fixture present
     */
    protected static function validateAgainstFixture(DOMDocument $doc, string $root): ?array
    {
        $map = [
            'HospitalStatus' => 'have-lite.xsd',
            'EDXLResourceMessage' => 'rm-lite.xsd',
            'EDXLSitRep' => 'sitrep-lite.xsd',
            'EDXLDistribution' => 'de-lite.xsd',
        ];

        $file = $map[$root] ?? null;
        if ($file === null) {
            return null;
        }

        $path = self::FIXTURES_PATH.'/'.$file;
        if (! is_file($path)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $ok = @$doc->schemaValidate($path);
        $libErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($err) => trim($err->message ?? ''),
            $libErrors,
        )));
    }
}
