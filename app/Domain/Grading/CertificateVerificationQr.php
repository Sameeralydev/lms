<?php

namespace App\Domain\Grading;

use chillerlan\QRCode\QRCode;

final class CertificateVerificationQr
{
    /** @return list<list<bool>> */
    public function matrix(string $verificationUrl): array
    {
        $qrCode = new QRCode;
        $qrCode->addByteSegment($verificationUrl);

        /** @var list<list<bool>> $matrix */
        $matrix = $qrCode->getQRMatrix()->getBooleanMatrix();

        return $matrix;
    }
}
