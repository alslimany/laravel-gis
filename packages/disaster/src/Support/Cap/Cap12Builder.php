<?php

namespace Lumina\Disaster\Support\Cap;

final class Cap12Builder
{
    public function build(Cap12Document $document): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">'
            .'<identifier>'.$this->esc($document->identifier).'</identifier>'
            .'<sender>'.$this->esc($document->sender).'</sender>'
            .'<sent>'.$this->esc($document->sent).'</sent>'
            .'<status>'.$this->esc($document->status).'</status>'
            .'<msgType>'.$this->esc($document->msgType).'</msgType>'
            .'<scope>'.$this->esc($document->scope).'</scope>';

        if ($document->references !== null && $document->references !== '') {
            $xml .= '<references>'.$this->esc($document->references).'</references>';
        }

        foreach ($document->infoBlocks as $info) {
            $xml .= $this->infoBlock($info);
        }

        return $xml.'</alert>';
    }

    /**
     * CAP references element: "sender,identifier,sent"
     */
    public function referencesLine(string $sender, string $identifier, string $sent): string
    {
        return $sender.','.$identifier.','.$sent;
    }

    /**
     * CAP polygon: space-separated "lat,lon" pairs; first point repeated at end.
     *
     * @param  list<array{0: float, 1: float}>  $ring  lat/lon pairs
     */
    public function polygonString(array $ring): string
    {
        if ($ring === []) {
            return '';
        }

        $pairs = [];
        foreach ($ring as $point) {
            $pairs[] = $point[0].','.$point[1];
        }

        $first = $pairs[0];
        $last = $pairs[array_key_last($pairs)];
        if ($first !== $last) {
            $pairs[] = $first;
        }

        return implode(' ', $pairs);
    }

    /**
     * @param  array{
     *     language: string,
     *     category: string,
     *     event: string,
     *     urgency: string,
     *     severity: string,
     *     certainty: string,
     *     headline: string,
     *     description: string,
     *     expires?: string|null,
     *     area_desc?: string|null,
     *     polygon?: list<array{0: float, 1: float}>|null
     * }  $info
     */
    private function infoBlock(array $info): string
    {
        $xml = '<info>'
            .'<language>'.$this->esc($info['language']).'</language>'
            .'<category>'.$this->esc($info['category']).'</category>'
            .'<event>'.$this->esc($info['event']).'</event>'
            .'<urgency>'.$this->esc($info['urgency']).'</urgency>'
            .'<severity>'.$this->esc($info['severity']).'</severity>'
            .'<certainty>'.$this->esc($info['certainty']).'</certainty>';

        if (! empty($info['expires'])) {
            $xml .= '<expires>'.$this->esc((string) $info['expires']).'</expires>';
        }

        $xml .= '<headline>'.$this->esc($info['headline']).'</headline>'
            .'<description>'.$this->esc($info['description']).'</description>';

        $polygon = $info['polygon'] ?? null;
        $areaDesc = $info['area_desc'] ?? null;
        if ($polygon !== null && $polygon !== []) {
            $xml .= '<area>'
                .'<areaDesc>'.$this->esc($areaDesc ?? 'Affected area').'</areaDesc>'
                .'<polygon>'.$this->esc($this->polygonString($polygon)).'</polygon>'
                .'</area>';
        } elseif ($areaDesc !== null && $areaDesc !== '') {
            $xml .= '<area><areaDesc>'.$this->esc($areaDesc).'</areaDesc></area>';
        }

        return $xml.'</info>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
