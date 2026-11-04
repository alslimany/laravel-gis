<?php

namespace Lumina\Disaster\Support\Cap;

/**
 * Pragmatic CAP 1.2 structural validator (required alert/info elements).
 * Optionally cross-checks against a trimmed fixture XSD when DOMDocument is available.
 */
final class Cap12Validator
{
    private const REQUIRED_ALERT = [
        'identifier',
        'sender',
        'sent',
        'status',
        'msgType',
        'scope',
    ];

    private const REQUIRED_INFO = [
        'category',
        'event',
        'urgency',
        'severity',
        'certainty',
    ];

    private const ALLOWED_STATUS = ['Actual', 'Exercise', 'System', 'Test', 'Draft'];

    private const ALLOWED_MSG_TYPE = ['Alert', 'Update', 'Cancel', 'Ack', 'Error'];

    private const ALLOWED_SCOPE = ['Public', 'Restricted', 'Private'];

    /**
     * @return list<string> Validation error messages (empty = valid).
     */
    public function validate(string $capXml): array
    {
        $errors = [];

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = @$dom->loadXML($capXml);
        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            foreach ($libxmlErrors as $err) {
                $errors[] = trim($err->message);
            }

            return $errors === [] ? ['CAP XML is not well-formed.'] : $errors;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cap', 'urn:oasis:names:tc:emergency:cap:1.2');

        $alert = $xpath->query('/cap:alert');
        if ($alert === false || $alert->length !== 1) {
            return ['Root element must be a single cap:alert.'];
        }

        foreach (self::REQUIRED_ALERT as $element) {
            $nodes = $xpath->query('/cap:alert/cap:'.$element);
            if ($nodes === false || $nodes->length < 1 || trim((string) $nodes->item(0)?->textContent) === '') {
                $errors[] = "Missing required alert element: {$element}.";
            }
        }

        $status = trim((string) $xpath->evaluate('string(/cap:alert/cap:status)'));
        if ($status !== '' && ! in_array($status, self::ALLOWED_STATUS, true)) {
            $errors[] = "Invalid status: {$status}.";
        }

        $msgType = trim((string) $xpath->evaluate('string(/cap:alert/cap:msgType)'));
        if ($msgType !== '' && ! in_array($msgType, self::ALLOWED_MSG_TYPE, true)) {
            $errors[] = "Invalid msgType: {$msgType}.";
        }

        $scope = trim((string) $xpath->evaluate('string(/cap:alert/cap:scope)'));
        if ($scope !== '' && ! in_array($scope, self::ALLOWED_SCOPE, true)) {
            $errors[] = "Invalid scope: {$scope}.";
        }

        if (in_array($msgType, ['Update', 'Cancel'], true)) {
            $references = trim((string) $xpath->evaluate('string(/cap:alert/cap:references)'));
            if ($references === '') {
                $errors[] = "msgType {$msgType} requires a references element.";
            }
        }

        $infoNodes = $xpath->query('/cap:alert/cap:info');
        if ($infoNodes === false || $infoNodes->length < 1) {
            $errors[] = 'At least one info block is required.';
        } else {
            foreach ($infoNodes as $index => $infoNode) {
                if (! $infoNode instanceof \DOMElement) {
                    continue;
                }
                foreach (self::REQUIRED_INFO as $element) {
                    $child = $xpath->query('cap:'.$element, $infoNode);
                    if ($child === false || $child->length < 1 || trim((string) $child->item(0)?->textContent) === '') {
                        $errors[] = 'info['.$index.'] missing required element: '.$element.'.';
                    }
                }
            }
        }

        // Optional trimmed XSD cross-check when structural checks already pass.
        if ($errors === []) {
            $xsdErrors = $this->validateAgainstFixtureXsd($dom);
            foreach ($xsdErrors as $xsdError) {
                $errors[] = $xsdError;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    private function validateAgainstFixtureXsd(\DOMDocument $dom): array
    {
        $fixture = $this->trimmedXsdPath();
        if ($fixture === null || ! method_exists($dom, 'schemaValidate')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $ok = @$dom->schemaValidate($fixture);
        $xsdErrors = [];
        if (! $ok) {
            foreach (libxml_get_errors() as $err) {
                $message = trim($err->message);
                if ($message !== '') {
                    $xsdErrors[] = 'XSD: '.$message;
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xsdErrors;
    }

    public function isValid(string $capXml): bool
    {
        return $this->validate($capXml) === [];
    }

    private function trimmedXsdPath(): ?string
    {
        $path = dirname(__DIR__, 3).'/docs/fixtures/cap/cap12-trimmed.xsd';

        return is_file($path) ? $path : null;
    }
}
