<?php
namespace CFDIReader\PostValidations\Validators;

use CFDIReader\CFDIReader;
use CFDIReader\PostValidations\Issues;
use CfdiUtils\CadenaOrigen;
use CfdiUtils\Certificado as UtilCertificado;
use CfdiUtils\CfdiCertificado;

/**
 * This class validate that:
 * - The certificate exists and is valid
 * - It matches with the emisor RFC and name
 * - The seal match with the cfdi
 */
class Certificado extends AbstractValidator
{
    /** @var CadenaOrigen|null */
    private $cadenaOrigen;

    public function setCadenaOrigen(CadenaOrigen $cadenaOrigen = null)
    {
        $this->cadenaOrigen = $cadenaOrigen;
    }

    public function getCadenaOrigen(): CadenaOrigen
    {
        if (! $this->hasCadenaOrigen()) {
            throw new \RuntimeException('The CadenaOrigen object has not been set');
        }
        return $this->cadenaOrigen;
    }

    public function hasCadenaOrigen(): bool
    {
        return ($this->cadenaOrigen instanceof CadenaOrigen);
    }

    public function validate(CFDIReader $cfdi, Issues $issues)
    {
        // setup the AbstractValidator Helper class
        $this->setup($cfdi, $issues);

        // create the certificate
        $extractor = new CfdiCertificado($cfdi->document());
        try {
            $certificado = $extractor->obtain();
        } catch (\Exception $ex) {
            $this->errors->add('No se pudo obtener el certificado del comprobante');
            return;
        }

        $this->validateNoCertificado($certificado);
        $this->validateRfc($certificado);
        $this->validateNombre($certificado);
        $this->validateFecha($certificado);

        // validate certificate seal
        if ($this->hasCadenaOrigen()) {
            $this->validateSello($certificado, $cfdi->getVersion(), $this->getCadenaOrigen()->build($cfdi->source()));
        }
    }

    private function validateNoCertificado(UtilCertificado $certificado)
    {
        if ($certificado->getSerial() !== (string) $this->comprobante['noCertificado']) {
            $this->errors->add(sprintf(
                'El número del certificado extraido (%s) no coincide con el reportado en el comprobante (%s)',
                $certificado->getSerial(),
                $this->comprobante['noCertificado']
            ));
        }
    }

    private function validateRfc(UtilCertificado $certificado)
    {
        $emisorRfc = $this->obtainEmisorAttribute('rfc');
        if ($certificado->getRfc() !== $emisorRfc) {
            $this->errors->add(sprintf(
                'El certificado extraido contiene el RFC (%s) que no coincide con el RFC reportado en el emisor (%s)',
                $certificado->getRfc(),
                $emisorRfc
            ));
        }
    }

    private function validateNombre(UtilCertificado $certificado)
    {
        $emisorNombre = $this->obtainEmisorAttribute('nombre');
        if ('' === $emisorNombre) {
            return;
        }
        if (! $this->compareNames($certificado->getName(), $emisorNombre)) {
            $this->warnings->add(sprintf(
                'El certificado extraido contiene la razón social "%s"'
                . ' que no coincide con el la razón social reportado en el emisor "%s"',
                $certificado->getName(),
                $emisorNombre
            ));
        }
    }

    private function validateFecha(UtilCertificado $certificado)
    {
        $fecha = $this->obtainFecha();
        if (0 === $fecha) {
            $this->errors->add('La fecha del documento no fue encontrada');
            return;
        }
        if ($fecha < $certificado->getValidFrom()) {
            $this->errors->add(sprintf(
                'La fecha del documento %s es menor a la fecha de vigencia del certificado %s',
                date('Y-m-d H:i:s', $fecha),
                date('Y-m-d H:i:s', $certificado->getValidFrom())
            ));
        }
        if ($fecha > $certificado->getValidTo()) {
            $this->errors->add(sprintf(
                'La fecha del documento %s es mayor a la fecha de vigencia del certificado %s',
                date('Y-m-d H:i:s', $fecha),
                date('Y-m-d H:i:s', $certificado->getValidTo())
            ));
        }
    }

    private function validateSello(UtilCertificado $certificado, string $version, string $cadena)
    {
        $algorithms = [OPENSSL_ALGO_SHA256];
        if ('3.2' === $version) {
            $algorithms[] = OPENSSL_ALGO_SHA1;
        }
        $sello = $this->obtainSello();
        if ('' !== $sello) {
            $selloIsValid = false;
            foreach ($algorithms as $algorithm) {
                if ($certificado->verify($cadena, $sello, $algorithm)) {
                    $selloIsValid = true;
                    break;
                }
            }
            if (! $selloIsValid) {
                $this->errors->add(
                    'La verificación del sello del CFDI no coincide, probablemente el CFDI fue alterado o mal generado'
                );
            }
        }
    }

    private function obtainEmisorAttribute(string $attribute): string
    {
        if (! isset($this->comprobante->emisor) || ! isset($this->comprobante->emisor[$attribute])) {
            return '';
        }
        return (string) $this->comprobante->emisor[$attribute];
    }

    private function obtainSello(): string
    {
        $selloBase64 = (string) $this->comprobante['sello'];
        if (false === $sello = @base64_decode($selloBase64, true)) {
            $this->errors->add('El sello del comprobante fiscal digital no está en base 64');
            return '';
        }
        return $sello;
    }

    private function compareNames(string $first, string $second): bool
    {
        return (0 === strcasecmp($this->castNombre($first), $this->castNombre($second)));
    }

    private function castNombre(string $nombre): string
    {
        return str_replace([' ', '.'], '', $nombre);
    }

    private function obtainFecha(): int
    {
        if (! isset($this->comprobante['fecha'])) {
            return 0;
        }
        return strtotime($this->comprobante['fecha']);
    }
}