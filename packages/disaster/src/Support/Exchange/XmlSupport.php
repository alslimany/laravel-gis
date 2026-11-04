<?php

namespace Lumina\Disaster\Support\Exchange;

use DOMDocument;
use DOMElement;
use DOMNode;

final class XmlSupport
{
    public static function document(string $xml): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $previous = libxml_use_internal_errors(true);
        $ok = @$doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok || $doc->documentElement === null) {
            throw new \InvalidArgumentException('Invalid XML payload.');
        }

        return $doc;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function text(?string $value): string
    {
        return self::escape((string) ($value ?? ''));
    }

    /**
     * @param  list<string>  $path
     */
    public static function firstText(DOMDocument|DOMNode $node, array $path): ?string
    {
        $current = $node instanceof DOMDocument ? $node->documentElement : $node;
        if (! $current instanceof DOMNode) {
            return null;
        }

        foreach ($path as $segment) {
            $found = null;
            foreach ($current->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === $segment) {
                    $found = $child;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $current = $found;
        }

        $text = trim($current->textContent ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * @return list<DOMElement>
     */
    public static function childrenNamed(DOMNode $parent, string $localName): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $out[] = $child;
            }
        }

        return $out;
    }

    public static function unwrapContentObject(DOMDocument $doc): ?DOMDocument
    {
        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'EDXLDistribution') {
            return null;
        }

        foreach (self::childrenNamed($root, 'contentObject') as $contentObject) {
            foreach (self::childrenNamed($contentObject, 'xmlContent') as $xmlContent) {
                foreach ($xmlContent->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $inner = new DOMDocument('1.0', 'UTF-8');
                        $imported = $inner->importNode($child, true);
                        $inner->appendChild($imported);

                        return $inner;
                    }
                }
            }
        }

        return null;
    }
}
