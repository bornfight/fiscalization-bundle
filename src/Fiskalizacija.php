<?php

namespace Nticaric\Fiskalizacija;

/**
 *
 * PHP API za fiskalizaciju računa
 *
 * @version 1.0
 * @author Nenad Tičarić <nticaric@gmail.com>
 * @project Fiskalizacija
 */

use DOMDocument;
use DOMElement;
use Exception;

class Fiskalizacija
{

    public $certificate;
    private $security;
    private $url = "https://cis.porezna-uprava.hr:8449/FiskalizacijaService";

    public function __construct($path, $pass, $security = 'SSL', $demo = false)
    {
        if ($demo == true) {
            $this->url = "https://cistest.apis-it.hr:8449/FiskalizacijaServiceTest";
        }
        $this->setCertificate($path, $pass);
        $this->privateKeyResource = openssl_pkey_get_private($this->certificate['pkey'], $pass);
        $this->publicCertificateData = openssl_x509_parse($this->certificate['cert']);
        $this->security = $security;
    }

    public function setCertificate($path, $pass)
    {
        $pkcs12 = $this->readCertificateFromDisk($path);
        openssl_pkcs12_read($pkcs12, $this->certificate, $pass);
    }

    public function readCertificateFromDisk($path)
    {
        $cert = @file_get_contents($path);
        if (false === $cert) {
            throw new \Exception("Ne mogu procitati certifikat sa lokacije: " .
                $path, 1);
        }
        return $cert;
    }

    public function getPrivateKey()
    {
        return $this->certificate['pkey'];
    }

    public function signXML($XMLRequest)
    {
        $XMLRequestDOMDoc = new DOMDocument();
        $XMLRequestDOMDoc->loadXML($XMLRequest);

        $canonical = $XMLRequestDOMDoc->C14N();
        $DigestValue = base64_encode(hash('sha1', $canonical, true));

        $rootElem = $XMLRequestDOMDoc->documentElement;

        $SignatureNode = $rootElem->appendChild(new DOMElement('Signature'));
        $SignatureNode->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');

        $SignedInfoNode = $SignatureNode->appendChild(new DOMElement('SignedInfo'));
        $SignedInfoNode->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');

        $CanonicalizationMethodNode = $SignedInfoNode->appendChild(new DOMElement('CanonicalizationMethod'));
        $CanonicalizationMethodNode->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');

        $SignatureMethodNode = $SignedInfoNode->appendChild(new DOMElement('SignatureMethod'));
        $SignatureMethodNode->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');

        $ReferenceNode = $SignedInfoNode->appendChild(new DOMElement('Reference'));
        $ReferenceNode->setAttribute('URI', sprintf('#%s', $XMLRequestDOMDoc->documentElement->getAttribute('Id')));

        $TransformsNode = $ReferenceNode->appendChild(new DOMElement('Transforms'));

        $Transform1Node = $TransformsNode->appendChild(new DOMElement('Transform'));
        $Transform1Node->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');

        $Transform2Node = $TransformsNode->appendChild(new DOMElement('Transform'));
        $Transform2Node->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');

        $DigestMethodNode = $ReferenceNode->appendChild(new DOMElement('DigestMethod'));
        $DigestMethodNode->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');

        $ReferenceNode->appendChild(new DOMElement('DigestValue', $DigestValue));

        $SignedInfoNode = $XMLRequestDOMDoc->getElementsByTagName('SignedInfo')->item(0);

        $X509IssuerName = $this->getIssuerName();
        $X509IssuerSerial = $this->publicCertificateData['serialNumber'];
        if (strpos($X509IssuerSerial, '0x') === 0) {
            $X509IssuerSerial = $this->bchexdec($X509IssuerSerial);
        }

        $publicCertificatePureString = str_replace('-----BEGIN CERTIFICATE-----', '', $this->certificate['cert']);
        $publicCertificatePureString = str_replace('-----END CERTIFICATE-----', '', $publicCertificatePureString);

        $this->signedInfoSignature = null;

        if (!openssl_sign($SignedInfoNode->C14N(true), $this->signedInfoSignature, $this->privateKeyResource, OPENSSL_ALGO_SHA1)) {
            throw new Exception('Unable to sign the request');
        }

        $SignatureNode = $XMLRequestDOMDoc->getElementsByTagName('Signature')->item(0);
        $SignatureValueNode = new DOMElement('SignatureValue', base64_encode($this->signedInfoSignature));
        $SignatureNode->appendChild($SignatureValueNode);

        $KeyInfoNode = $SignatureNode->appendChild(new DOMElement('KeyInfo'));

        $X509DataNode = $KeyInfoNode->appendChild(new DOMElement('X509Data'));
        $X509CertificateNode = new DOMElement('X509Certificate', $publicCertificatePureString);
        $X509DataNode->appendChild($X509CertificateNode);

        $X509IssuerSerialNode = $X509DataNode->appendChild(new DOMElement('X509IssuerSerial'));

        $X509IssuerNameNode = new DOMElement('X509IssuerName', $X509IssuerName);
        $X509IssuerSerialNode->appendChild($X509IssuerNameNode);

        $X509SerialNumberNode = new DOMElement('X509SerialNumber', $X509IssuerSerial);
        $X509IssuerSerialNode->appendChild($X509SerialNumberNode);

        $envelope = new DOMDocument();

        $envelope->loadXML('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
		    <soapenv:Body></soapenv:Body>
		</soapenv:Envelope>');

        $envelope->encoding = 'UTF-8';
        $envelope->version = '1.0';
        $XMLRequestType = $XMLRequestDOMDoc->documentElement->localName;
        $XMLRequestTypeNode = $XMLRequestDOMDoc->getElementsByTagName($XMLRequestType)->item(0);
        $XMLRequestTypeNode = $envelope->importNode($XMLRequestTypeNode, true);

        $envelope->getElementsByTagName('Body')->item(0)->appendChild($XMLRequestTypeNode);
        return $envelope->saveXML();
    }

    public function plainXML($XMLRequest)
    {
        $XMLRequestDOMDoc = new DOMDocument();
        $XMLRequestDOMDoc->loadXML($XMLRequest);

        $envelope = new DOMDocument();

        $envelope->loadXML('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
		    <soapenv:Body></soapenv:Body>
		</soapenv:Envelope>');

        $envelope->encoding = 'UTF-8';
        $envelope->version = '1.0';
        $XMLRequestType = $XMLRequestDOMDoc->documentElement->localName;
        $XMLRequestTypeNode = $XMLRequestDOMDoc->getElementsByTagName($XMLRequestType)->item(0);
        $XMLRequestTypeNode = $envelope->importNode($XMLRequestTypeNode, true);

        $envelope->getElementsByTagName('Body')->item(0)->appendChild($XMLRequestTypeNode);
        return $envelope->saveXML();
    }

    public function sendSoap($payload): string
    {
        $ch = curl_init();

        $options = array(
            CURLOPT_URL => $this->url,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            //CURLOPT_CAINFO => './tests/democacert.cer.pem',
        );

        switch ($this->security) {
            case 'SSL':
                break;
            case 'TLS':
                curl_setopt($ch, CURLOPT_SSLVERSION, 6);
                break;
            default:
                throw new \InvalidArgumentException(
                    'Treći parametar konstruktora klase Fiskalizacija mora biti SSL ili TLS!'
                );
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response !== false) {
            curl_close($ch);
            return $this->parseResponse($response, $code);
        }

        throw new Exception(curl_error($ch));
        curl_close($ch);
    }

    public function parseResponse(string $response, $code = 4): string
    {
        if ($code === 200) {
            return $response;
        }

        $errorsMap = $this->parseErrors($response);

        if ($errorsMap === null || count($errorsMap) === 0) {
            throw new Exception(print_r($response, true), $code);
        }

        $composedErrors = array_map(
            fn (string $code, string $errorMessage) => sprintf('%s: %s', $code, $errorMessage),
            array_keys($errorsMap),
            $errorsMap
        );

        throw new Exception(implode('; ', $composedErrors));
    }

    public function parseErrors(string $response): ?array
    {
        $DOMResponse = new DOMDocument();
        $DOMResponse->loadXML($response);

        $errors = $DOMResponse->getElementsByTagName('Greske')->item(0);

        if (null === $errors || false === $errors->hasChildNodes()) {
            return null;
        }

        $errorsMap = [];

        /** @var DOMElement $childNode */
        foreach ($errors->childNodes as $childNode) {
            $errorMessageNode = $childNode->getElementsByTagName('PorukaGreske')->item(0);
            $errorCodeNode = $childNode->getElementsByTagName('SifraGreske')->item(0);

            if ($errorCodeNode === null || $errorMessageNode === null) {
                continue;
            }

            $errorsMap[$errorCodeNode->nodeValue] = $errorMessageNode->nodeValue;
        }

        return $errorsMap;
    }

    public function parseUniqueBillIdentifier(string $response): ?string
    {
        $DOMResponse = new DOMDocument();
        $DOMResponse->loadXML($response);

        $uniqueBillIdNode = $DOMResponse->getElementsByTagName('Jir')->item(0);

        if (null === $uniqueBillIdNode) {
            return null;
        }

        return $uniqueBillIdNode->nodeValue;
    }

    public function responseContainsErrors(string $response): bool
    {
        $errorsMap = $this->parseErrors($response);

        if ($errorsMap === null || count($errorsMap) === 0) {
            return false;
        }

        if (count($errorsMap) > 1) {
            return true;
        }

        if (isset($errorsMap['v100'])) {
            return false;
        }

        return true;
    }

    private function getIssuerName(): string
    {
        $X509Issuer = $this->publicCertificateData['issuer'];
        return implode(
            ',',
            array_map(
                fn (string $key, string $value) => sprintf('%s=%s', $key, $value),
                array_keys($X509Issuer),
                $X509Issuer
            )
        );
    }

    public function bchexdec($hex): string
    {
        $dec = 0;
        $len = strlen($hex);

        for ($i = 1; $i <= $len; $i++) {
            $dec = bcadd($dec, bcmul((string) hexdec($hex[$i - 1]), bcpow('16', (string) ($len - $i))));
        }

        return $dec;
    }
}
