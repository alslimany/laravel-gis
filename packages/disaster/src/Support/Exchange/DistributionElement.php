<?php

namespace Lumina\Disaster\Support\Exchange;

use Illuminate\Support\Str;

/**
 * EDXL Distribution Element (DE) wrapper for routing payloads to partner orgs.
 *
 * Supported fields (pragmatic subset):
 * - distributionID, senderID, dateTimeSent
 * - distributionStatus (Actual), distributionType (Report|Request|Response|Ack)
 * - combinedConfidentiality (Unclassified)
 * - explicitAddress[] (scheme=organization_id, value=target org id)
 * - contentObject.xmlContent (embedded HAVE / RM / SitRep XML)
 */
final class DistributionElement
{
    /**
     * @param  list<int|string>  $targetOrganizationIds
     */
    public static function wrap(
        string $innerXml,
        string $senderId,
        array $targetOrganizationIds = [],
        string $contentDescription = 'EDXL',
        string $distributionType = 'Report',
        ?string $distributionId = null,
    ): string {
        $inner = XmlSupport::document($innerXml);
        $payload = $inner->saveXML($inner->documentElement);
        if ($payload === false) {
            throw new \RuntimeException('Unable to serialize inner EDXL payload.');
        }

        $addresses = '';
        foreach ($targetOrganizationIds as $orgId) {
            $addresses .= '<explicitAddress>'
                .'<explicitAddressScheme>organization_id</explicitAddressScheme>'
                .'<explicitAddressValue>'.XmlSupport::text((string) $orgId).'</explicitAddressValue>'
                .'</explicitAddress>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<EDXLDistribution xmlns="urn:oasis:names:tc:emergency:EDXL:DE:1.0" schemaVersion="1.0-lite">'
            .'<distributionID>'.XmlSupport::text($distributionId ?? ('dm-de-'.Str::uuid()->toString())).'</distributionID>'
            .'<senderID>'.XmlSupport::text($senderId).'</senderID>'
            .'<dateTimeSent>'.XmlSupport::text(now()->toIso8601String()).'</dateTimeSent>'
            .'<distributionStatus>Actual</distributionStatus>'
            .'<distributionType>'.XmlSupport::text($distributionType).'</distributionType>'
            .'<combinedConfidentiality>Unclassified</combinedConfidentiality>'
            .$addresses
            .'<contentObject>'
            .'<contentDescription>'.XmlSupport::text($contentDescription).'</contentDescription>'
            .'<xmlContent>'.$payload.'</xmlContent>'
            .'</contentObject>'
            .'</EDXLDistribution>';
    }

    /**
     * @return array{distribution_id: ?string, sender_id: ?string, target_organization_ids: list<string>, content_description: ?string, inner_xml: string}
     */
    public static function unwrap(string $xml): array
    {
        $doc = XmlSupport::document($xml);
        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'EDXLDistribution') {
            throw new \InvalidArgumentException('Expected EDXLDistribution root.');
        }

        $targets = [];
        foreach (XmlSupport::childrenNamed($root, 'explicitAddress') as $address) {
            $scheme = XmlSupport::firstText($address, ['explicitAddressScheme']);
            $value = XmlSupport::firstText($address, ['explicitAddressValue']);
            if ($scheme === 'organization_id' && $value !== null) {
                $targets[] = $value;
            }
        }

        $inner = XmlSupport::unwrapContentObject($doc);
        if ($inner === null || $inner->documentElement === null) {
            throw new \InvalidArgumentException('EDXLDistribution missing xmlContent.');
        }

        $innerXml = $inner->saveXML($inner->documentElement);
        if ($innerXml === false) {
            throw new \RuntimeException('Unable to extract DE content.');
        }

        $description = null;
        foreach (XmlSupport::childrenNamed($root, 'contentObject') as $contentObject) {
            $description = XmlSupport::firstText($contentObject, ['contentDescription']);
            break;
        }

        return [
            'distribution_id' => XmlSupport::firstText($doc, ['distributionID']),
            'sender_id' => XmlSupport::firstText($doc, ['senderID']),
            'target_organization_ids' => $targets,
            'content_description' => $description,
            'inner_xml' => $innerXml,
        ];
    }
}
